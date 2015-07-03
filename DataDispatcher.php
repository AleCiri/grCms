<?php

/**
 * DataDispatcher si occupa di recuperare i dati da DB o da JSON
 *
 * @author aleciri
 */
class DataDispatcher {

    private $db;    

    /**
     * Constructor..
     * PDOException throwable
     */
    public function __construct() {
        $this->db = DB_Manager::getInstance();
    }

    public function __get($name) {
        return $this->$name;
    }
    
   
    /**
     * Decide che template  usare come layout, per ora assume che ci siano 2 livelli di navigazione 
	 * <br/>OPTIMIZE: da rifare, ottimizzata e che prevede piu livelli di navigazione
     * @param string $pageUrl
     * @return string ritorna il nome del template (solo nome senza path ne estensione)
     */
    public function getTemplateName($page){        
        $data = $this->getPageData($page);
        $tpl = $data['nome_file'];   
        
        return $tpl;
    }
    
    public function fillResources($table,&$tableRows){   
        $tableInfo = $this->tableInfo($table);
        foreach ($tableInfo as $column) {
            switch ($column['Type']){
                case 'video':
                case 'image':
                    foreach ($tableRows as &$row) {    
                        $where = 'id_entita=:id_entita AND media LIKE CONCAT("resource/",:table,"/",:field,"/%")';
                        $bind = array(  ':id_entita'=>$row['id_'.$table],
                                        ':table'=>$table,
                                        ':field'=>$column['Field']);
                        $data = $this->db->select( 'risorsa' , $where, $bind, 'media, info');
                        
						$row[$column['Field']] = array();
						
						$areThereInfo = false;
                        if($data){
							
							foreach ($data as $value) {
								if(!empty($value['info'])){
									$areThereInfo = true;
									break;
								}
							}
							
							if($areThereInfo){
								foreach ($data as $value) {								
									array_push(
										$row[$column['Field']],	
										array(	'path'=>$value['media'],
												'info'=>json_decode($value['info'],true)
										)
									);
								}
							}
							else{
								foreach ($data as $value) {
									array_push(
										$row[$column['Field']],
										$value['media']
									);
								}
							}
						}
                    }
                    break;                        
            }            
        }
    }
    
    /**
     * Ritorna i contenuti della pagina
     * @param string $page l'identificativoURL della pagina     
     * @return array
     * @throws Exception 
     */
    public function getPageData($page){
                
        $tables = 'data_pagina p 
					JOIN template_html t ON t.id_template_html = p.fk_id_template_html';        
        $where = 'p.identificativoURL=:id_p';
        $bind = array(':id_p'=>$page);
        $data = $this->db->select( $tables , $where, $bind, 'p.*,t.nome_file');
        if(empty($data)){
            throw new Exception('No page found:'.$page);
        }
        
        $this->fillResources('pagina', $data);
        
        $data=array_pop($data);
        
        return $data;
    }
    
	/**
	 * Crea un array con tutti le pagine del sito, costituito in questo modo:
	 * ogni elemernto ha come chiave l'id della pagina e come valore un array con i dati delle pagine figlie
	 * Per ora lavora solo su due livelli, se si vuole fare un albero multilivello bisognerebbe scrivere un algoritmo ricorsivo.
	 * @return array
	 * @throws Exception 
	 */
	public function getMenu(){
		
		//seleziono le pagine da db
		$tables = 'data_pagina';		
        $data = $this->db->select( $tables, "menu=1 ORDER BY ordinamento DESC " );
        if(empty($data)){
            throw new Exception('No page found:'.$page);
        }
		$this->fillResources('pagina', $data);
        
		$menu = array();
		$associativeData = array();
		
		// creo un array associativo
		foreach ($data as &$page) {
			$associativeData[$page['id_pagina']] = $page;			
		}
			
		$menu = $this->_mapTreeMenu($associativeData);
	
        return $menu;
	}
	
	private function _mapTreeMenu($dataset) {
		$tree = array();
		foreach ($dataset as $id=>&$node) {
			if ($node['fk_id_pagina_padre'] == 0) {
				$tree[$id] = &$node;
			} else {
				if (!isset($dataset[$node['fk_id_pagina_padre']]['children'])) $dataset[$node['fk_id_pagina_padre']]['children'] = array();
				$dataset[$node['fk_id_pagina_padre']]['children'][$id] = &$node;
			}
		}

		return $tree;
	}
	
	/**
	 * Crea un array con tutti le pagine del sito, costituito in questo modo:
	 * ogni elemernto ha come chiave l'id della pagina e come valore un array con i dati delle pagine figlie
	 * Per ora lavora solo su due livelli, se si vuole fare un albero multilivello bisognerebbe scrivere un algoritmo ricorsivo.
	 * @param bool $xml indica se si vuole la sitemap xml o html
	 * @return array
	 * @throws Exception 
	 */
	public function getSitemapData($xml=false){
		
		//sitemap = 0 -> la pagina non compare in sitemap xml e html
		//sitemap = 1 -> la pagina compare in sitempap xml e html
		//sitemap = 2 -> la pagina compare in solo in sitemap html
		//
		//seleziono le pagine da db
		$tables = 'pagina';		
		if($xml){
			$condition = 'sitemap = 1 ';
		}
		else{
			$condition = 'sitemap > 0 ';
		}
		$condition .= 'ORDER BY ordinamento_sitemap ';
        $data = $this->db->select( $tables, $condition );
        if(empty($data)){
            throw new Exception('No page found:'.$page);
        }
		$this->fillResources('pagina', $data);
        
		$menu = array();
		$associativeData = array();
		
		// creo un array associativo
		foreach ($data as &$page) {
			$associativeData[$page['id_pagina']] = $page;			
		}
			
		$menu = $this->_mapTreeSitemap($associativeData);
	
        return $menu;
	}
	
	private function _mapTreeSitemap($dataset) {
		$tree = array();
		foreach ($dataset as $id=>&$node) {
			if ($node['fk_id_pagina_padre'] == 0) {
				$tree[$id] = &$node;
			} else {
				if (!isset($dataset[$node['fk_id_pagina_padre']]['children'])) $dataset[$node['fk_id_pagina_padre']]['children'] = array();
				$dataset[$node['fk_id_pagina_padre']]['children'][$id] = &$node;
			}
		}

		return $tree;
	}
    
    /**
     * Restituisce i contenuti di una pagina e quelli delle entita associate ()
     * @param string $page l'identificativoURL della pagina     
     * @return array
     * @throws Exception 
     */
    public function getPageContentData($page){
        
        //comincio a riempire con i contenuti della pagina
        $contentData = $this->getPageData($page);
        
        //cerco i contenuti correlati alla pagina
        $table = 'contenuto_pagina cp 
                JOIN data_pagina p ON (p.id_data_pagina=cp.fk_id_pagina || cp.fk_id_pagina = substring_index(`id_pagina`, "_" , 1 ) )'; //questo serve per le pagine create dinamiciamente nella vista, a partire dai contenuti
        $where = 'p.identificativoURL=:id_p';
        $bind = array(':id_p'=>$page);
        $pageContents = $this->db->select( $table , $where, $bind, 'cp.*');
		
        //se ci sono altri contenuti collegati
        if($pageContents){
            //estraggo i contenuti in base al tipo di origine dati
            foreach ($pageContents as $pageContent) {
				$orderField = ($pageContent['order']?$pageContent['order']:'id_'.$pageContent['riferimento'].' DESC');
				$where = '';
				if ($pageContent['where']!='')
				{
					$where=' WHERE '.$pageContent['where'];
				}
                switch ($pageContent['tipo']) {                	
                    case 'tabella':
                        //seleziono i dati del riferimento                                           
                        $bind = array();
                        $tables = $pageContent['riferimento'];						
                        //riempo i dati delle tabelle collegate come N a N                        
                        if($pageContent['fk_1_a_1']){
                            $oneToOneTables=explode(',', $pageContent['fk_1_a_1']);
                            foreach ($oneToOneTables as $oneToOneTable) {
                                $tables .= ' JOIN '.$oneToOneTable.' ON '.$oneToOneTable.'.id_'.$oneToOneTable.'  = '.$pageContent['riferimento'].'.fk_id_'.$oneToOneTable;
                            }
                        }												
                        $data = $this->db->keyValSelect($tables.' '.$where.' ORDER BY '.$orderField);                        
                        if(empty($data)){							
                            $data = array();
                        }
                        //riempo gli array delle risorse
                        $this->fillResources($pageContent['riferimento'], $data);
						
                        //riempo i dati delle tabelle collegate come N a N                        
                        if($pageContent['fk_1_a_N']){
                            $oneToNTables=explode(',', $pageContent['fk_1_a_N']);
                            foreach ($oneToNTables as $oneToNTable) {
                                $this->fillForeignKey($pageContent['riferimento'],$oneToNTable, $data);
                            }
                        }
                        
                        $contentData[$pageContent['riferimento']] = $data;
                        break;
                        
                    case 'vista':
                        $where = '';
                        $bind = array();
                        $data = $this->db->keyValSelect($pageContent['riferimento'].' ORDER BY '.$orderField);                        
                        if(empty($data)){
                            $data = array();
                        }
						preg_match('/data_([a-z]+)(_[a-z]+)*/',$pageContent['riferimento'],$tableName);
                        $this->fillResources($tableName[1], $data);
                        $contentData[$pageContent['riferimento']]=$data;
                        break;
                        
                    default:
                        throw new Exception('Tipo origine dati sconosciuta: '.$pageContent['tipo']);
                        break;
                }
            }
        }
        
        return $contentData;
    }   
    
    public function tableInfo($table){
        $sql = 'SHOW FULL COLUMNS FROM '.$table.';';
        $table = $this->db->run($sql);
        if (count($table) < 2) {
            throw new Exception('Inconsistent table');
        }
        foreach ($table as &$column) {
            $comment = $column['Comment'];
            if($comment!=''){
                $rules = json_decode($comment,true);
                $column['Type'] = isset($rules['type'])?$rules['type']:'';
                $column['FkTable'] = isset($rules['fkTable'])?$rules['fkTable']:'';
                $column['Relation'] = isset($rules['relation'])?$rules['relation']:'';
                $column['DisplayField'] = isset($rules['displayField'])?$rules['displayField']:'';
            }            
        }
        return $table;
    }
    
    public function fillForeignKey($sourceTable,$fktable,&$tableRows){

        foreach ($tableRows as &$row) {
            $where = 'fk_id_'.$sourceTable.'=:id';
            $bind = array( ':id'=>$row['id_'.$sourceTable] );
            $data = $this->db->select( $fktable , $where, $bind );
            if(!empty($data)){
                if(key_exists($fktable, $row)){
                    throw new Exception("Foreign NaN key can't have name ".$fktable);
                }
                $row[$fktable] = $data;
            }
        }
    }
	
	
	public function load($table,$id){
		$tableRows = $this->db->select($table, 'id_'.$table.' = '.$id);
		$this->fillResources($table, $tableRows);
		return $tableRows;
}
	
	public function insert($table,$data){		
		return $this->db->insert($table, $data);
	}

	/**
	 * Ritorna un array chiave valore dei template esistenti nel sistema
	 * <br/>La chiave e' il nome del template, il valore e' l'id dello stesso
	 * @return array 
	 */
    public function getTemplates(){
        $rowSet = $this->db->select("template_html");
		$templates = array();
        foreach($rowSet as $template){
            $templates[$template["nome_file"]] = $template["id_template_html"];
        }
        return $templates;
    }

	/**
	 * Funzione ricorsiva che scorre l'albero del menu e trova tutte pagine al livello della pagina richiesta
	 * <br/>OPTIMIZE: si protrebbe far passare un parametro opzionale per escludere il ritorno della pagina richiesta
	 * @param array $pages l'albero del menu
	 * @param int $idPagina l'id della pagina di cui si vogliono le sorelle
	 * @return array porzione del menu con le pagine sorelle (e la pagina richiesta)	 * 
	 */
    public function getPageSiblings($pages, $idPagina)
    {    
		$siblings = array();
		
		foreach($pages as $page){
			
			if($idPagina == $page["id_pagina"]) {
				return $pages;
			}			
			else if(isset($page["children"])){
				$siblings = $this->getPageSiblings($page["children"], $idPagina);				
			}
			if(!empty($siblings)){
				break;
			}
		}
		if(!empty($siblings)){
			return $siblings;
		}
    }
	
	/**
	 * Ritorna i dati della pagina al primo livello del menu, rispetto alla pagna in cui ci si trova
	 * @param array $pages l'albero del menu
	 * @param int $idPagina l'id della pagina di cui si vuole la sezione di primo livello
	 * @return array i dati 
	 */
	public function getFirstLevelPage($pages, $idPagina)
    {
		$pageFound = false;
		
		foreach($pages as $page){
			
			if($idPagina == $page["id_pagina"]) {
				$pageFound = true;
			}			
			else if(isset($page["children"])){
				$pageFound = $this->getFirstLevelPage($page["children"], $idPagina);				
			}
			if($pageFound){
				return $page;
			}
		}
    }
	
	
	private function _parseTransition($row) {
		return array(
			'type' => $row['tipo'],
			'params' => array(
				'cssProperties' => (array) json_decode($row['proprieta_css']),
				'initializer' => (array) json_decode($row['inizializzatore']),
				'destroyer' => (array) json_decode($row['distruttore']),
				'target' => $row['target'],
				'changeContent' => $row['cambia_contenuto']
			)
		);
	}
	
	public function getCommonTransitions(){
		$table = "transizione";				
		$where = "fk_id_pagina = 0";
		$rows = $this->db->select( $table , $where );
		
		$transitions = array();
		if($rows){
			//rewrite transitions in usable format
			foreach ($rows as $row) {
				$transitions[$row['selettore']] = $this->_parseTransition($row);
			}
		}
		
		return $transitions;
	}

	public function getPageTransitions($identificativoURL){
		$table = "	`transizione`AS `t`
					INNER JOIN `data_pagina`AS `p` ON `t`.`fk_id_pagina` = `p`.`id_pagina`";		
		$where = "`p`.`identificativoURL` = :identificativoURL";		
		$bind = array(':identificativoURL'=>$identificativoURL);		
		if (!$rows = $this->db->select( $table , $where, $bind))
		{
			$rows=array();
		}
		$transitions = array();
		//rewrite transitions in usable format
		if($rows){
			foreach ($rows as $row) {
				$transitions[$row['selettore']] = $this->_parseTransition($row);
			}
		}
		
		return $transitions;
	}
	
}
?>