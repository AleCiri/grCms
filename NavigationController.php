<?php
/**
 * Controlla la logica di Navigazione e renderizzale pagine  da visualizzare usand i template
 *
 * @author aleciri
 */
class NavigationController {

	//objects    
	protected $rainTPL;
	protected $dataDispatcher;
    
	//config
	private $tplDir;
    private $tplExt;
    private $tplPrefix;
	
	//data
    protected $currentPageUrl;
    protected $currentPageData;
    protected $currentPageTemplate;
	protected $currentPageSiblings;
	protected $menuTree;
	

    /**
     * Constructor..
     * PDOException throwable
     */
    public function __construct($templateDirectory, $cacheDirectory, $templateExtension='html', $templatePrefix = '', $debug = false) {
        RainTPL::$tpl_dir = $templateDirectory; // template directory
        RainTPL::$cache_dir = $cacheDirectory; // cache directory
        RainTPL::$tpl_ext = $templateExtension;
        RainTPL::$path_replace = false;
        $this->rainTPL = new RainTPL();        
        $this->dataDispatcher = new DataDispatcher();        
        $this->tplDir = $templateDirectory;
        $this->tplExt = $templateExtension;
        $this->tplPrefix = $templatePrefix;
		$this->debug = $debug;
    }


    public function __get($name) {
        return $this->$name;
    }
      
	protected function _sortByOrder($a, $b) {
		if ($a['ordinamento'] == $b['ordinamento']) {
			return 0;
		}
		return ($a['ordinamento'] < $b['ordinamento']) ? -1 : 1;		
	}

	public function loadPage(){
		// recupero gli elementi specifici della pagina
		$this->currentPageTemplate = $this->dataDispatcher->getTemplateName($this->currentPageUrl);
		// all page data and related contents data
		$this->currentPageData = $this->dataDispatcher->getPageContentData($this->currentPageUrl);			
		// recupero le pagine e i loro filgi per costruire il menu
		$this->menuTree = $this->dataDispatcher->getMenu();
		$this->sitemapTree = $this->dataDispatcher->getSitemapData();
		// fratelli della pagina corrente visualizzata
		$this->currentPageSiblings = $this->dataDispatcher->getPageSiblings($this->menuTree, $this->currentPageData['id_pagina']);
		if($this->currentPageSiblings){
			if(array_key_exists('ordinamento', $this->currentPageData)){
				uasort($this->currentPageSiblings, array("NavigationController", "_sortByOrder"));
			}
		}
		$_f = 'compile'.ucfirst($this->currentPageTemplate);

		if(method_exists($this, $_f)){
			$this->$_f();
		}
		// vettore dei possibili template (per fare i controlli usando i nomi degli stessi come chiave)
		$this->templates = $this->dataDispatcher->getTemplates();
	}
	
	/**
	 * Declare into the template all the needed variables: both page data and page contents data
	 * @param string $pageUrl 
	 */
	public function compile($pageUrl){
		
        $this->_404 = false;
        $error = '';
        $this->currentPageUrl = $pageUrl;
		
        try {
			$this->loadPage();
            
            $tplPath = $this->tplDir.$this->tplPrefix.$this->currentPageTemplate.'.'.$this->tplExt;
            if (!file_exists($tplPath)){
                $error = 'Template file not found:'.$tplPath;
                $this->_404 = true;
            }
        }
        catch (Exception $e){            
            //in debug mandiamo gli errori in output
            if($this->debug){
                throw $e;
            }
            //altrimenti li mettiamo nella variabile errore 
            //che viene stampata nella console js e rimandiamo alla 404
            else{
                $error = $e->getMessage();				
                $this->_404 = true;
            }
        }
		
		if($this->_404){
			$this->currentPageUrl = "404";
			$this->loadPage();
            header("HTTP/1.0 404 Not Found");            
        }
		
		// assign all data			
		$this->rainTPL->assign( "data", $this->currentPageData );	
		$this->rainTPL->assign( "siblings", $this->currentPageSiblings );
		$this->rainTPL->assign( "templates", $this->templates );		
		$this->rainTPL->assign( "error", $error );
		$this->rainTPL->assign( "urlParams", $pageUrl );
		$this->rainTPL->assign( "menu", $this->menuTree );
		$this->rainTPL->assign( "sitemap", $this->sitemapTree );
	}
	
	
	/**
	 * Declare a custom variable into the template engine scope
	 * @param string $name
	 * @param mixed $var 
	 */
	public function assign($name,$var){
		$this->rainTPL->assign( $name, $var );
	}
	
	/**
	 * Render the page drawing the template with the assigned variable and echo the output 
	 */
    public function render(){        
        $this->rainTPL->draw( $this->tplPrefix.$this->currentPageTemplate );
    }
	
	
	/*
	 * Retrieve all pages transitions (destination and source)
	 */
	public function getPageTransitions($prevPageUrl){
		//get common site transitions
		$commonTransitions = $this->dataDispatcher->getCommonTransitions();

		//get transition of destination page			
		$destTransitions = $this->dataDispatcher->getPageTransitions($this->currentPageUrl);

		$sourceTransitions = array();
		if($prevPageUrl){
			//get transition of source page		
			$sourceTransitions = $this->dataDispatcher->getPageTransitions($prevPageUrl);
		}
		
		// merge transitions
		$transitions = array_merge($commonTransitions, $sourceTransitions, $destTransitions);
		
		return $transitions;
	}
	
	/**
	 * Build a JSON with all the data used for an asyncronous 	 
	 * @return string the JSON 
	 */
	public function getAsyncNavigationData($prevPageUrl=""){
		
		// get the compiled and rendered html contents of the destination page
		ob_start();
		$this->render();
		$content = ob_get_contents();
		ob_end_clean();

		$page = str_replace("\n", '', $content);
		$page = str_replace("\t", '', $page);
		
        // delete scripts with class "noAjax" from the page
		$page =  preg_replace('/(<script [\w "\/:_\.=&;\?\-]*class="[\w ]*noAjax[\w ]*"[\w "\/:_\.=&;\?\-]*>[\w\S\s]*?<\/script>)/', "", $page);
		
        $error = preg_last_error();
        if($error){
            throw new Exception(preg_error_string($error));
        }
        
		// collect all the page transitions in an associative array
		$actions = $this->getPageTransitions($prevPageUrl);		
		
		$json = json_encode(array(
			"page" => $page, 
			"actions" => $actions, 
			"menu" => $this->menuTree, 
			'pageId' => $this->currentPageData['id_pagina'], 
			"pageData" => $this->currentPageData,
			"pageSiblings" => $this->currentPageSiblings            
		));
		
		if(function_exists('json_last_error')){
			$error = json_last_error();
			if($error){
				throw new Exception($error);
			}
		}
		
		return $json;
	}
}