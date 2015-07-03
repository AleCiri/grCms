<?php

class Json
{
    protected $db;
    
    public function __construct() {
        $this->db = DB_Manager::getInstance();
    }
    
    public function getJson($name, $terms= '', $binds=null, $select='', $limitStart = '', $limitOffset = '')
    {
        $return = '';
        $id = '';
        
        $sql = "SELECT *
                        FROM json 
                        WHERE name = '".$name."'";
        $resultJsonSql = $this->db->run($sql);
        $resultJsonSql = array_pop($resultJsonSql);

        //se ho una lista di parametri da selezionare li scelgo altrimenti prendo tutto
        $selectSQL = ($select)?$select:'*';
        $sqlValue = "SELECT ".$selectSQL." FROM ".$resultJsonSql['name'];
        if($terms != '')
        {
            $sqlValue .= " WHERE ".$terms;
        }
		if($resultJsonSql['order']){
			$sqlValue .= ' ORDER BY '.$resultJsonSql['order'];
		}
        
        if ($limitStart != '' && $limitOffset != '')
        {
            $sqlValue .= ' LIMIT '.$limitStart.', '.$limitOffset;
        }
        
        $resultValueSql = $this->db->run($sqlValue, $binds);
        
        if(!empty($resultValueSql))
        {
            switch($resultJsonSql['type'])
            {
                case 'table':   foreach($resultValueSql as $valueResult)
                                {
                                    $id = $valueResult['id_'.$resultJsonSql['name']];
                                    $temporaneyReturn = '';
                                    foreach($valueResult as $key=>$value)
                                    {
                                        $sqlColumns = "SHOW FULL COLUMNS
                                                        FROM ".$resultJsonSql['name']."
                                                        WHERE Field = '".$key."'";
                                        $queryColumnSql = $this->db->run($sqlColumns);
                                        $resultColumnSql = array_pop($queryColumnSql);

                                        $appo = $this->createJson($value, $resultColumnSql, $resultJsonSql['name'], $id);
                                        if(!empty($appo))
                                        {
                                            foreach ($appo as $key => $value) {
                                                $temporaneyReturn[$key] = $value;
                                            }
                                        }
                                    }
                                    $return[] = $temporaneyReturn;
                                }
                                if(!empty($return))
                                    $return = json_encode($return);
                    break;

                case 'json':    foreach($resultValueSql as $valueResult)
                                {
                                    $arrayToMod='';

                                    $jsonBone = $resultJsonSql['reference'];
									//prendo i placeholders {{...}}
                                    preg_match_all("/\{\{([\w\_\-\:\,\"\s])+\}\}/", $jsonBone, $arrayToMod);
									// e li parso uno a uno
                                    foreach($arrayToMod[0] as $key=>$value)
                                    {
										//se e' un json
                                        if(strpos($value,':') !== false)
                                        {
											// lo converto in array
                                            $arrayJson = str_replace("{{", '{', $value);
                                            $arrayJson = str_replace("}}", '}', $arrayJson);
                                            $arrayJson = json_decode($arrayJson);
											// e lo parso 
                                            $appo = $this->createJsonFromTemplate($valueResult, $arrayJson, $resultJsonSql['name']);
                                            if(!empty($appo))
                                                $jsonBone = str_replace($value, $appo, $jsonBone);
                                            else
                                                $jsonBone = str_replace($value, '""', $jsonBone);
                                        }
										//altrimenti sono solo campi della tabella di riferimento da riempire
                                        else
                                        {
                                            $valueAppo = str_replace('}', '', $value);
                                            $valueAppo = str_replace('{', '', $valueAppo);
                                            $jsonBone = str_replace($value, str_replace("\r\n","", nl2br('"'.addcslashes($valueResult[$valueAppo], "\"") ) ).'"', $jsonBone);
                                        }
                                    }
                                    if(empty($return))
                                        $return = $jsonBone;
                                    else
                                        $return = $return.",".$jsonBone;
                                }
                                $return = str_replace('],[', '', $return);

                                $return = preg_replace('/}([\s])*{/', '},{', $return);
                    break;
            }
        }
        //die(nl2br(json_encode($return)));
        if(!empty($return))
            return $return;
        else
            return "";
    }
    
    function createJson($value, $field, $table, $id_entity)
    {
        $print='';
        $specs=json_decode($field['Comment']);
        if(!empty($specs))
            $commentType = $specs->type;
        else
            $commentType = '';
        $tipo = $field['Type'];
        $tipo = preg_replace('/\(.*/', '', $tipo);
        switch ($tipo){
            case 'varchar':
                switch ($commentType){
                   
                    case 'image': 
                    case 'video': 
						$print = $this->jsonMedia($field['Field'], $commentType, $table, $id_entity);
                        break;
                    
                    default:
                        $print = $this->jsonText($field, $value);
                        break;
                }            
                break;
            case 'int':
                switch ($commentType){
                    case 'table':
                        $print = $this->jsonTable($field, $id_entity);
                        break;
                    
                    default:
                        $print = $this->jsonNumber($field, $value);
                        break;
                }
                break;
            
            case 'date':
                $print = $this->jsonDate($field, $value);
                break;
            case 'datetime':
                $print = $this->jsonDateTime($field, $value);
                break;
            default:
                $print = $this->jsonText($field, $value);
                break;
        }
        return $print;
    }
    
    function createJsonFromTemplate($resultValueSql, $arrayJson, $table)
    {
        $return = '';
        $param= '';

        switch($arrayJson->type)
        {
            case 'table':
            case 'json':
                $param = "fk_id_".$table." = ".$resultValueSql['id_'.$table];
                $return = $this->getJson($arrayJson->fkTable, $param, '', isset($arrayJson->name)?$arrayJson->name:'');
                break;

            case 'external_table':
                $sql = "SELECT ".$arrayJson->select."
                                FROM ".$arrayJson->fkTable."
                                WHERE id_".$arrayJson->fkTable." = '".$resultValueSql['fk_id_'.$arrayJson->fkTable]."'";
                $row = $this->db->run($sql);
                $row = array_pop($row);
                $param = "id_".$row[$arrayJson->select]." = ".$resultValueSql['fk_id_'.$arrayJson->extKeyValue];
                $return = $this->getJson($row[$arrayJson->select], $param);
                break;

            case 'external_value':
                $sql = "SELECT ".$arrayJson->select."
                                FROM ".$arrayJson->fkTable."
                                WHERE id_".$arrayJson->fkTable." = '".$resultValueSql['fk_id_'.$arrayJson->fkTable]."'";
                $row = $this->db->run($sql);
                if(!empty($row))
                {
                    $row = array_pop($row);
                    $select = explode(',', $arrayJson->select);
					$appo = array();
                    foreach ($select as $value) {
                        $appo[] = '"'.$value.'" : "'.$row[$value].'"';
                    }
					$appo = '{'.implode($appo,',').'}';
                }
                else
                {
                $appo = '""';
                }
                $return = $appo;
                break;

            case 'image':
            case 'video':
                $param = $resultValueSql['id_'.$arrayJson->fkTable];
                $return = json_encode(array_pop($this->jsonMedia($arrayJson->name, $arrayJson->type, $table, $param)));  //TODO: controllare con 1 a N risorse se funziona comunque nonostante l'array_pop
                break;
        }
        return $return;
    }
    
    function jsonMedia($fieldName, $fieldType, $table, $id_entity)
    {
        global $_CONFIG;
        
        $chiave_array = $fieldName;
        if($fieldType == 'image'){
            $sql_media_tipo = GFE_RESOURCE_IMAGE;
        }
        elseif($fieldType == 'video'){
            $sql_media_tipo = GFE_RESOURCE_VIDEO;
        }        
		else{
			$sql_media_tipo = GFE_RESOURCE_HTML;
		}
        
        generaCartellaTabella($_CONFIG['root_path'].'/'."resource".'/', $table);
        generaCartellaTabella($_CONFIG['root_path'].'/'."resource".'/'.$table, $fieldName);
        
        $path=$_CONFIG['root_path'].'resource'.'/'.$table.'/'.$fieldName.'/';
        $print = '';
        
        $sql = "SELECT * FROM risorsa WHERE tipo = ".$sql_media_tipo." AND media LIKE '%".$table."/".$fieldName."/%' ";
        if(!empty($id_entity))
            $sql .= " AND id_entita= ".$id_entity;
        $query = $this->db->run($sql);
        
        $mod = 0;
        $count = 0;
        $count = !empty($print[$chiave_array]) ? (count($print[$chiave_array])+1) : $count;
        if(!empty($query))
        {
            foreach($query as $row)
            {
                if ($handle = opendir($path))
                {
                    while (($file = readdir($handle)) !== false) 
                    {
                        //se sono un video controllo solo il nome dl file --> TODO Controllare che esistano tutte le estensioni del video
                        if ($sql_media_tipo == GFE_RESOURCE_VIDEO){
                            $print[$chiave_array][$count]['media'] = $row['media'];
                            $mod = 1;
                        }
                        else{
                            if(strcmp($file, basename($row['media']))==0)
                            {
                                $print[$chiave_array][$count]['media'] = $row['media'];
                                $mod = 1;
                            }
                        }
                        
                    }
                    $count ++;
                }
                closedir($handle);
            }
        }
        
        if($mod == 0)
        {
            $print[$chiave_array] = '';
        }
        return $print;
    }
    
    function jsonText($field,$value)
    {
        $print = array($field['Field']=>$value);
        return $print;
    }
    
    function jsonTable($field,$id_entity)
    {
        $print = '';
        $fKey = json_decode($field['Comment']);

        $sql = 'SELECT * FROM '.$fKey->fkTable;
        if(isset($id_entity))
            $sql .= ' WHERE id_'.$fKey->fkTable.' = '.$id_entity;
        $sql .= ' ORDER BY '.$fKey->displayField;
        $query = $this->db->run($sql);
        $print='';
        if($query){
           /* $count = 0;
            $count = !empty($print[$fKey->fkTable]) ? (count($print[$fKey->fkTable])+1) : $count; */
            if(count($query) > 0)
            {
            /*/*    while ($result = $query->fetch(PDO::FETCH_ASSOC)) 
                    {
                    $print[$fKey->fkTable][$count]['fk_id_'.$fKey->fkTable] = $id_entity;
                    $print[$fKey->fkTable][$count][$fKey->displayField] = $result[$fKey->displayField];
                    $count ++;
                }*/
               $result = array_pop($query);
               $print['fk_id_'.$fKey->fkTable] = $id_entity;
               $print[$fKey->displayField] = $result[$fKey->displayField];
                
            }
            else
            {
               //$print[$fKey->fkTable] = '';
               $print['fk_id_'.$fKey->fkTable] = 0;
               $print[$fKey->displayField] = "";
            }
        }
        return $print;
    }
    
    function jsonNumber($field,$value)
    {
        $print = array($field['Field']=>$value);
        return $print;
    }
    
    function jsonDate($field,$value){
        $print = array($field['Field']=>dateView($value));
        return $print;
    }
    
    function jsonDateTime($field,$value){
        $print = array($field['Field']=>datetimeView($value));
        return $print;
    }
}?>