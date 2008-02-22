<?php

    /**
     * Main DAV server class
     * 
     * @package Sabre
     * @subpackage DAV
     * @version $Id: Server.php 7 2008-01-02 05:47:17Z evertpot $
     * @copyright Copyright (C) 2007, 2008 Rooftop Solutions. All rights reserved.
     * @author Evert Pot (http://www.rooftopsolutions.nl/) 
     * @license license http://www.freebsd.org/copyright/license.html  BSD License (4 Clause)
     */
    class Sabre_DAV_Server {

        /**
         * Inifinity is used for some request supporting the HTTP Depth header and indicates that the operation should traverse the entire tree
         */
        const DEPTH_INFINITY = -1;

        /**
         * Nodes that are files, should have this as the type property
         */
        const NODE_FILE = 1;

        /**
         * Nodes that are directories, should use this value as the type property
         */
        const NODE_DIRECTORY = 2;

        /**
         * The tree object
         * 
         * @var Sabre_DAV_Tree 
         */
        protected $tree;

        /**
         * The base uri 
         * `
         * @var string 
         */
        protected $baseUri;

        /**
         * Class constructor 
         * 
         * @param Sabre_DAV_Tree $tree The tree object 
         * @return void
         */
        public function __construct(Sabre_DAV_Tree $tree) {

            $this->tree = $tree;

        }

        /**
         * Starts the DAV Server 
         *
         * @return void
         */
        public function exec() {

            try {

                $this->invoke();

            } catch (Sabre_DAV_Exception $e) {

                $this->sendHTTPStatus($e->getHTTPCode());
                throw $e;

            } catch (Exception $e) {

                $this->sendHTTPStatus(500);
                throw $e;

            }

        }

        /**
         * Sets the base responding uri
         * 
         * @param string $uri
         * @return void
         */
        public function setBaseUri($uri) {

            $this->baseUri = $uri;    

        }

        // {{{ HTTP Method implementations
        
        /**
         * HTTP OPTIONS 
         * 
         * @return void
         */
        protected function httpOptions() {

            $this->addHeader('Allows',strtoupper(implode(' ',$this->getAllowedMethods())));
            $this->addHeader('DAV','1');

        }

        /**
         * HTTP GET
         * 
         * @return void
         */
        protected function httpGet() {

            echo $this->tree->get($this->getRequestUri());

        }

        /**
         * HTTP PROPFIND 
         * 
         * @return void
         */
        protected function httpPropfind() {

            // $xml = new Sabre_DAV_XMLReader(file_get_contents('php://input'));
            // $properties = $xml->parsePropfindRequest();
          
            $depth = $this->getHTTPDepth(1);
            // The only two options for the depth of a propfind is 0 or 1 
            if ($depth!=0) $depth = 1;

            // The requested path
            $path = $this->getRequestUri();

            $fileList = $this->tree->getNodeInfo($path,$depth);

            // This is a multi-status response
            $this->sendHTTPStatus(207);
            $data = $this->generatePropfindResponse($fileList,$properties);
            echo $data;

        }
                

        // }}}
        // {{{ HTTP/WebDAV protocol helpers 

        /**
         * Returns a full HTTP status header based on a status code 
         * 
         * @param int $code 
         * @return string 
         */
        public function getHTTPStatus($code) {
            
            $msg = array(
                200 => 'Ok',
                201 => 'Created',
                204 => 'No Content',
                207 => 'Multi-Status',
                400 => 'Bad request',
                403 => 'Forbidden',
                404 => 'Not Found',
                405 => 'Method not allowed',
                409 => 'Conflict',
                412 => 'Precondition failed',
                415 => 'Unsupported Media Type',
                423 => 'Locked',
                500 => 'Internal Server Error',
                501 => 'Method not implemented',
           ); 

            return 'HTTP/1.1 ' . $code . ' ' . $msg[$code];

        }

        /**
         * Sends an HTTP status header to the client 
         * 
         * @param int $code HTTP status code 
         * @return void
         */
        public function sendHTTPStatus($code) {

            header($this->getHTTPStatus($code));

        }

        /**
         * Handles a http request, and execute a method based on its name 
         * 
         * @return void
         */
        protected function invoke() {

            $method = strtolower($_SERVER['REQUEST_METHOD']);

            // Make sure this is a HTTP method we support
            if (in_array($method,$this->getAllowedMethods())) {

                call_user_func(array($this,'http' . $method));

            } else {

                // Unsupported method
                throw new Sabre_DAV_MethodNotImplementedException();

            }

        }

        /**
         * Returns an array with all the supported HTTP methods 
         * 
         * @return array 
         */
        protected function getAllowedMethods() {

            return array('options','get','head','post','delete','trace','propfind','copy','mkcol','put','move','proppatch', /* 'lock','unlock' */);

        }

        /**
         * Adds an HTTP response header 
         * 
         * @param string $name 
         * @param string $value 
         * @return void
         */
        protected function addHeader($name,$value) {

            header($name . ': ' . str_replace(array("\n","\r"),array('\n','\r'),$value));

        }

        /**
         * Gets the uri for the request, keeping the base uri into consideration 
         * 
         * @return string
         */
        public function getRequestUri() {

            return $this->calculateUri($_SERVER['REQUEST_URI']);

        }

        /**
         * Calculates the uri for a request, making sure that the base uri is stripped out 
         * 
         * @param string $uri 
         * @throws Sabre_DAV_PermissionDeniedException A permission denied exception is thrown whenever there was an attempt to supply a uri outside of the base uri
         * @return string
         */
        public function calculateUri($uri) {

            if ($uri[0]!='/' && strpos($uri,'://')) {

                $uri = parse_url($uri,PHP_URL_PATH);

            }

            if (strpos($uri,$this->baseUri)===0) {

                return trim(urldecode(substr($uri,strlen($this->baseUri))),'/');

            } else {

                throw new Sabre_DAV_PermissionDeniedException('Requested uri (' . $uri . ') is out of base uri (' . $this->baseUri . ')');

            }

        }

        /**
         * Returns the HTTP depth header
         *
         * This method returns the contents of the HTTP depth request header. If the depth header was 'infinity' it will return the Sabre_DAV_Server::DEPTH_INFINITY object
         * It is possible to supply a default depth value, which is used when the depth header has invalid content, or is completely non-existant
         * 
         * @param mixed $default 
         * @return int 
         */
        public function getHTTPDepth($default = self::DEPTH_INFINITY) {

            // If its not set, we'll grab the default
            $depth = isset($_SERVER['HTTP_DEPTH'])?$_SERVER['HTTP_DEPTH']:$default;

            // Infinity
            if ($depth == 'infinity') $depth = self::DEPTH_INFINITY;
            else {
                // If its an unknown value. we'll grab the default
                if ($depth!=="0" && (int)$depth==0) $depth == $default;
            }

            return $depth;

        }

        // }}} 
        // {{{ XML Writers  
        
        
        /**
         * Generates a WebDAV propfind response body based on a list of nodes 
         * 
         * @param array $list 
         * @return string 
         */
        private function generatePropfindResponse($list) {

            $xw = new XMLWriter();
            $xw->openMemory();
            $xw->setIndent(true);
            $xw->startDocument('1.0','UTF-8');
            $xw->startElementNS('d','multistatus','DAV:');

            foreach($list as $entry) {

                $this->writeProperty($xw,$_SERVER['REQUEST_URI'],$entry);

            }

            $xw->endElement();
            return $xw->outputMemory();

        }

        /**
         * Generates the xml for a single item in a propfind response.
         *
         * This method is called by generatePropfindResponse
         * 
         * @param XMLWriter $xw 
         * @param string $baseurl 
         * @param array $data 
         * @return void
         */
        private function writeProperty(XMLWriter $xw,$baseurl,$data) {

            $xw->startElement('d:response');
            $xw->startElement('d:href');

            // Base url : /services/dav/mydirectory
            $url = rtrim(urldecode($baseurl),'/');

            // Adding the node in the directory
            if (isset($data['name']) && trim($data['name'],'/')) $url.= '/' . trim((isset($data['name'])?$data['name']:''),'/');

            $url = explode('/',$url);

            foreach($url as $k=>$item) $url[$k] = rawurlencode($item);

            $url = implode('/',$url);

            // Adding the protocol and hostname. We'll also append a slash if this is a collection
            $xw->text('http://' . $_SERVER['HTTP_HOST'] . $url . ($data['type']==self::NODE_DIRECTORY&&$url?'/':''));
            $xw->endElement(); //d:href

            $xw->startElement('d:propstat');
            $xw->startElement('d:prop');

            // Last modification property
            $xw->startElement('d:getlastmodified');
            $xw->writeAttribute('xmlns:b','urn:uuid:c2f41010-65b3-11d1-a29f-00aa00c14882/');
            $xw->writeAttribute('b:dt','dateTime.rfc1123');
            $modified = isset($data['modified'])?$data['modified']:time();
            if (!(int)$modified) $modified = strtotime($modified);
            $xw->text(date(DATE_RFC1123,$modified));
            $xw->endElement(); // d:getlastmodified

            // Content-length property
            $xw->startElement('d:getcontentlength');
            $xw->text(isset($item['size'])?(int)$item['size']:'0');
            $xw->endElement(); // d:getcontentlength
                   
            // Resource type property

            $xw->startElement('d:resourcetype');
            if (isset($data['type'])&&$data['type']==self::NODE_DIRECTORY) $xw->writeElement('d:collection','');
            $xw->endElement(); // d:resourcetype

            $xw->endElement(); // d:prop
           
            $xw->writeElement('d:status',$this->getHTTPStatus(200));
           
            $xw->endElement(); // :d:propstat

            $xw->endElement(); // d:response
        }

        // }}}

    }

?>
