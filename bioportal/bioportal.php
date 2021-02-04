<?php
/**
Copyright (C) 2011-2014 Michel Dumontier

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
of the Software, and to permit persons to whom the Software is furnished to do
so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/

require_once(__DIR__.'/../../arc2/ARC2.php'); // available on git @ https://github.com/semsol/arc2.git

/**
 * A Bio2RDF converter for bioportal ontologies
 * @version 2.0
 * @author Michel Dumontier
*/

class BioportalParser extends Bio2RDFizer
{
	function __construct($argv) {
		parent::__construct($argv,'bioportal');
		parent::addParameter('files',true,null,'all','all or comma-separated list of ontology short names to process');
		parent::addParameter('download_url',false,null,'http://data.bioontology.org/');
		parent::addParameter('exclude',false,null,"AURA,HOOM",'ontologies to exclude - use acronyms');
		parent::addParameter('continue_from',false,null,"",'the ontology abbreviation to restart from');
		parent::addParameter('detail',false,'min|min+|max','max','min:generate rdfs:label and rdfs:subClassOf axioms; min+: min + owl axioms');

		parent::initialize();
		return TRUE;
	}

	function Run()
	{
		$dataset_description = '';
		$idir = parent::getParameterValue('indir');
		$odir = parent::getParameterValue('outdir');

		if(parent::getParameterValue('ncbo_api_key')) {
			$apikey = "?apikey=".parent::getParameterValue('ncbo_api_key');
		} else {
			if(file_exists(parent::getParameterValue('ncbo_api_key_file'))) $apikey = "?apikey=".trim(file_get_contents(parent::getParameterValue('ncbo_api_key_file')));
			else {
				echo "You must provide an NCBO API key either as a file or as a parameter".PHP_EOL;
				exit;
			}
		}

		// get the list of ontologies from bioportal
		$olist = $idir."ontolist.json";
		if(!file_exists($olist) || parent::getParameterValue('download') == 'true') {
			echo "downloading ontology list...";
			$r_olist = parent::getParameterValue('download_url').'ontologies'.$apikey;
			file_put_contents($olist, file_get_contents($r_olist));
			echo "done".PHP_EOL;
		}

		// include
		if(parent::getParameterValue('files') == 'all') {
			$include_list = array('all');
		} else {
			$include_list = explode(",",parent::getParameterValue('files'));
		}

		// exclude
		$exclude_list = array();
		if(parent::getParameterValue('exclude') != '') {
			$exclude_list = explode(",",parent::getParameterValue('exclude'));
		}
		$continue_from = parent::getParameterValue('continue_from');	
		$go = true;
		if($continue_from) $go = false;
		// now go through the list of ontologies
		
		$ontologies = json_decode(file_get_contents($olist), false);
		$total = count($ontologies);
		foreach($ontologies AS $i => $o) {
			$label = (string) $o->name;
			$abbv = (string) $o->acronym;
			
			if($continue_from and $continue_from == $abbv) $go = true;
			if($go == false) continue;

			if(array_search($abbv,$exclude_list) !== FALSE) {
				continue;
			}
			if($include_list[0] != 'all') {
				// ignore if we don't find it in the include list OR we do find it in the exclude list
				if(array_search($abbv,$include_list) === FALSE) {
					continue;
				}
			} else if(array_search($abbv,$exclude_list) !== FALSE ) {
				continue;
			}

			// get info on the latest submission
			$uri = $o->links->latest_submission;
			$ls = json_decode(file_get_contents($uri.$apikey), true);
			if(!isset($ls['hasOntologyLanguage'])) {echo 'insufficient metadata'.PHP_EOL;continue;}

			$format = strtolower($ls['hasOntologyLanguage']);
			if($format != 'owl' and $format != 'obo') continue;
			echo "Processing ($i/$total) $abbv ... ";

			$version = $ls['version'];
			if(isset($ls['homepage'])) $homepage = $ls['homepage'];
			if(isset($ls['description'])) $description = $ls['description'];

			$rfile = $ls['ontology']['links']['download'];
			$lfile = $abbv.".".$format.".gz";
			if(!file_exists($idir.$lfile) or parent::getParameterValue('download') == 'true') {
				echo "downloading ... ";

				$ch = curl_init(); // create cURL handle (ch)
				$url = $rfile.$apikey;
				if (!$ch) die("Couldn't initialize a cURL handle");
				curl_setopt($ch, CURLOPT_URL,            $url);
				curl_setopt($ch, CURLOPT_HEADER,         1);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_TIMEOUT,        600);
				$data = curl_exec($ch);
				if(empty($data)) {
					echo "no content";
					continue;
				}

				$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
				$header = substr($data, 0, $header_size);
				preg_match("/filename=\"([^\"]+)\"/",$header,$m);
				if(isset($m[1])) {
					$filename = $m[1];
					if(strstr($filename,".zip"))  continue;

				} else {echo "error: no filename".PHP_EOL;continue;}

				$body = substr($data, $header_size);
				// now get the file suffix
				$path = pathinfo($filename);
				if(isset($path['extension'])) $ext  = $path['extension'];
				else {echo "error: no extension".PHP_EOL; continue;}

				$lz = "compress.zlib://".$idir.$lfile;
				file_put_contents($lz,$body);
				curl_close($ch);
				echo "done".PHP_EOL;
			}

			if(file_exists($idir.$lfile)) {
				parent::setReadFile($idir.$lfile, true);
				$gz = (strstr(parent::getParameterValue('output_format'),".gz") === FALSE)?false:true;
				$ofile = strtolower($abbv).".".parent::getParameterValue('output_format');
				parent::setWriteFile($odir.$ofile,$gz);

				// process
				echo "converting ... ";
				
				// let's double check the format
				$fp = gzopen($idir.$lfile,"r");
				$l = gzgets($fp);
				if(strstr($l,"xml")) $format= "owl";
				gzclose($fp);

				if($format == 'obo') {
					$this->OBO2RDF($abbv);
				} else if($format == 'owl') {
					$this->OWL2RDF($abbv);
					if(isset($this->unmapped_uri)) print_r($this->unmapped_uri);
					unset($this->unmapped_uri);
				} else {
					echo "no processor for $label (format $format)".PHP_EOL;
				}

				if(!file_exists($odir.$ofile)) { echo "no output".PHP_EOL;continue;}
				parent::getWriteFile()->close();
				parent::clear();

				$bVersion = parent::getParameterValue('bio2rdf_release');
				$source_file = (new DataResource($this))
                                	->setURI($rfile)
                                	->setTitle("$label")
                                	->setRetrievedDate( date ("Y-m-d\TG:i:s\Z", filemtime($idir.$lfile)))
                                	->setFormat("obo")
                                	->setPublisher("http://www.bioontology.org")
                                	->setHomepage("http://bioportal.bioontology.org/")
                                	->setRights("use-share-modify")
                                	->setLicense("http://www.bioontology.org/terms")
                                	->setDataset("http://identifiers.org/$abbv");

				$output_file = (new DataResource($this))
                                	->setURI("http://download.bio2rdf.org/release/$bVersion/bioportal/$ofile")
                                	->setTitle("Bio2RDF v$bVersion RDF version of $abbv")
                                	->setSource($source_file->getURI())
                                	->setCreator("https://github.com/bio2rdf/bio2rdf-scripts/blob/master/bioportal/bioportal.php")
                                	->setCreateDate( date("Y-m-d\TG:i:s\Z", filemtime($odir.$ofile)))
                                	->setHomepage("http://download.bio2rdf.org/release/$bVersion/bioportal/bioportal.html")
                                	->setPublisher("http://bio2rdf.org")
                                	->setRights("use-share-modify")
                                	->setRights("by-attribution")
                                	->setRights("restricted-by-source-license")
                                	->setLicense("http://creativecommons.org/licenses/by/3.0/")
									->setDataset(parent::getDatasetURI());

				if($gz) $output_file->setFormat("application/gzip");
				if(strstr(parent::getParameterValue('output_format'),"nt")) $output_file->setFormat("application/n-triples");
				else $output_file->setFormat("application/n-quads");

				if(!isset($dd)) {
					$dd = fopen($odir.'bio2rdf-bioportal.nq',"w");
				}
                fwrite($dd, $source_file->toRDF().$output_file->toRDF());
				fflush($dd);
				echo "done!".PHP_EOL;
			}
		}
		if(isset($dd)) fclose($dd);
		echo "done!".PHP_EOL;
	}

	private function OWL2RDF($abbv)
	{
		$filename = parent::getReadFile()->getFilename();
		$buf = file_get_contents("compress.zlib://".$filename);

		$parser = ARC2::getRDFXMLParser('file://'.$filename);
		$parser->parse("http://bio2rdf.org/bioportal#", $buf);
		$triples = $parser->getTriples();
		foreach($triples AS $i => $a) {
			$this->TriplifyMap($a, strtolower($abbv));
			parent::writeRDFBufferToWriteFile();
		}
		parent::clear();
	}
	
	// parse the URI into the base and fragment. find the corresponding prefix and bio2rdf_uri. 
	public function parseURI($uri)
	{
		$a['uri'] = $uri;
		$delims = array("#","_","/");
		foreach($delims AS $delim) {
			if(($pos = strrpos($uri,$delim)) !== FALSE) {
				$a['base_uri'] = substr($uri,0,$pos+1);
				$a['fragment'] = substr($uri,$pos+1);

				$a['prefix'] = parent::getRegistry()->getPrefixFromURI($a['base_uri']);
				if(isset($a['prefix'])) {
					if($a['base_uri'] == 'http://bio2rdf.org/') {
						$a['bio2rdf_uri'] = 'http://bio2rdf.org/'.$a['fragment'];
					} else {
						$a['bio2rdf_uri'] = 'http://bio2rdf.org/'.$a['prefix'].':'.$a['fragment'];
						$p_uri = parent::getRegistry()->getEntryValueByKey($a['prefix'], 'provider-uri');
						if(isset($p_uri)) {
							if($p_uri == $a['base_uri']) {
								$a['is_provider_uri'] = true;
							}
							$a['provider_uri'] = $p_uri;
						}
					}
					break;
				}
				
			}
		}
		if(!isset($a['base_uri'])) $a['base_uri'] = $uri;
		return $a;
	}
	

	public function TriplifyMap($a, $prefix)
	{
		$defaults = parent::getRegistry()->getDefaultURISchemes();
		$bio2rdf_priority = false;
		$mapping = true;

		// subject
		if($a['s_type'] == 'bnode') $a['s'] = 'http://bio2rdf.org/'.$prefix.'_resource:'.substr($a['s'],2);
		$u = $this->parseURI($a['s']);
		$s_uri = $u['uri'];
		if(isset($u['prefix'])) {
			if(!in_array($u['prefix'],$defaults)) {
				if($bio2rdf_priority) {
					$s_uri = $u['bio2rdf_uri'];
					if($mapping) {
						parent::addRDF(
							parent::triplify($s_uri,'owl:sameAs',$u['uri'])
						);
					}
				} else if($mapping) {
					parent::addRDF(
						parent::triplify($u['uri'],'owl:sameAs',$u['bio2rdf_uri'])
					);
				}
			}
		} else {
			// add to the registry of uris not found
			if(!isset($this->unmapped_uri[$u['base_uri']])) $this->unmapped_uri[$u['base_uri']] = 1;
			else $this->unmapped_uri[$u['base_uri']]++;
		}

		// predicate
		$u = $this->parseURI($a['p']);
		$p_uri = $u['uri'];
		if(isset($u['prefix'])) {
			if(!in_array($u['prefix'],$defaults)) {
				if($bio2rdf_priority) {
					$p_uri = $u['bio2rdf_uri'];
					if($mapping) {
						parent::addRDF(
							parent::triplify($p_uri,'owl:sameAs',$u['uri'])
						);
					}
				} else if($mapping) {
					parent::addRDF(
						parent::triplify($u['uri'],'owl:sameAs',$u['bio2rdf_uri'])
					);
				}
			}
		} else {
			// add to the registry of uris not found
			if(!isset($this->unmapped_uri[$u['base_uri']])) $this->unmapped_uri[$u['base_uri']] = 1;
			else $this->unmapped_uri[$u['base_uri']]++;
		}

		if($a['o_type'] == 'uri' || $a['o_type'] == 'bnode') {
			if($a['o_type'] == 'bnode') {
				$a['o'] = 'http://bio2rdf.org/'.$prefix.'_resource:'.substr($a['o'],2);
			}
			$u = $this->parseURI($a['o']);
			$o_uri = parent::makeSafeIRI($u['uri']);
			if(isset($u['prefix'])) {
				if(!in_array($u['prefix'],$defaults)) {
					if($bio2rdf_priority) {
						$o_uri = $u['bio2rdf_uri'];
						if($mapping) {
							parent::addRDF(
								parent::triplify($o_uri,'owl:sameAs',$u['uri'])
							);
						}
					} else if($mapping) {
						parent::addRDF(
							parent::triplify($u['uri'],'owl:sameAs',$u['bio2rdf_uri'])
						);
					}						
				}
			} else {
				// add to the registry of uris not found
				if(!isset($this->unmapped_uri[$u['base_uri']])) $this->unmapped_uri[$u['base_uri']] = 1;
				else $this->unmapped_uri[$u['base_uri']]++;
			}
		
			// add the triple
			parent::addRDF(
				parent::triplify($s_uri,$p_uri,$o_uri)
			);
			
		} else {
			parent::addRDF(
				parent::triplifyString($s_uri,$p_uri,$a['o'],(($a['o_datatype'] == '')?null:$a['o_datatype']),(($a['o_lang'] == '')?null:$a['o_lang']))
			);			
		}
	
	}

	
	function OBO2RDF($abbv)
	{
		$abbv = strtolower($abbv);
		if($abbv == "doid") $abbv = "do";
		$minimal = (parent::getParameterValue('detail') == 'min')?true:false;
		$minimalp = (parent::getParameterValue('detail') == 'min+')?true:false;
		$version = parent::getParameterValue("bio2rdf_release");

		$tid = '';
		$first = true;
		$is_a = false;
		$is_deprecated = false;
		$min = $buf = '';
		$ouri = "http://bio2rdf.org/lsr:".$abbv;

		$dataset_uri = $abbv."_resource:bio2rdf.dataset.$abbv.R".$version;
		parent::setGraphURI($dataset_uri);
		$buf = parent::triplify($ouri,"rdf:type","owl:Ontology");
		$graph_uri = '<'.parent::getRegistry()->getFQURI(parent::getGraphURI()).'>';
		$bid = 1;

		while(FALSE !== ($l = parent::getReadFile()->read())) {
			$lt = trim($l);
			if(strlen($lt) == 0) continue;
			if($lt[0] == '!') continue;

			if(strstr($l,"[Term]")) {
				// first node?
				if($first == true) { // ignore the first case
					$first = false;
				} else {
					if($tid != '' && $is_a == false && $is_deprecated == false) {
						$t = parent::triplify($tid,"rdfs:subClassOf","obo_vocabulary:Entity");
						$buf .= $t;
						$min .= $t;
					}
				}
				$is_a = false;
				$is_deprecated = false;
				
				unset($typedef);
				$term = '';
				$tid = '';
				continue;
			} else if(strstr($l,"[Typedef]")) {
				$is_a = false;
				$is_deprecated = false;
				
				unset($term);
				$tid = '';
				$typedef = '';
				continue;
			} 

			//echo "LINE: $l".PHP_EOL;
			
			// to fix error in obo generator
			$lt = str_replace("synonym ","synonym: ",$lt);
			$lt = preg_replace("/\{.*\} !/"," !",$lt);
			$a = explode(" !", $lt);
			if(isset($a[1])) $exc = trim($a[1]);
			$a = explode(": ",trim($a[0]),2);

			// let's go
			if(isset($intersection_of)) {
				if($a[0] != "intersection_of") {
			//		$intersection_of .= ")].".PHP_EOL;
					//$buf .= $intersection_of;
					if($minimalp) $min .= $intersection_of;
					unset($intersection_of);
				}
			}
			if(isset($relationship)) {
				if($a[0] != "relationship") {
				//	$relationship .= ")].".PHP_EOL;
					//$buf .= $relationship;
					if($minimalp) $min .= $relationship;
					unset($relationship);
				}
			}

			if(isset($typedef)) {	
				if($a[0] == "id") {
					$c = explode(":",$a[1]);
					if(count($c) == 1) {$ns = "obo";$id=$c[0];}
					else {$ns = strtolower($c[0]);$id=$c[1];}
					$id = str_replace( array("(",")"), array("_",""), $id);
					$tid = $ns.":".$id;
					echo $tid.PHP_EOL;
				} else if($a[0] == "name") {
					$name = stripslashes($a[1]);
					$buf .= parent::describeClass($tid,$name);
					$buf .= parent::triplifyString($tid,"dc:title",$name);
				} else if($a[0] == "is_a") {
					if(FALSE !== ($pos = strpos($a[1],"!"))) $a[1] = substr($a[1],0,$pos-1);
					$buf .= parent::triplify($tid,"rdfs:subPropertyOf","obo_vocabulary:".strtolower($a[1]));
				} else if($a[0] == "is_obsolete") {
					$buf .= parent::triplify($tid, "rdf:type", "owl:DeprecatedClass");
					$is_deprecated = true;
				} else {
					if($a[0][0] == "!") $a[0] = substr($a[0],1);
					$buf .= parent::triplifyString($tid,"obo_vocabulary:$a[0]", str_replace('"','',stripslashes($a[1])));
				}

			} else if(isset($term)) {
				if($a[0] == "is_obsolete" && $a[1] == "true") {
					$t = parent::triplify($tid, "rdf:type", "owl:DeprecatedClass");
					$t .= parent::triplify($tid, "rdfs:subClassOf", "owl:DeprecatedClass");
					
					$min .= $t;
					$buf .= $t;
					$is_deprecated = true;
				} else if($a[0] == "id") {	
					parent::getRegistry()->parseQName($a[1],$ns,$id);
					if(trim($ns) == '') $ns = "unspecified";			
					$tid = "$ns:$id";
//					$buf .= parent::describeClass($tid,null,"owl:Class");
//					$buf .= parent::triplify($tid,"rdfs:isDefinedBy",$ouri);					
				} else if($a[0] == "name") {
//					$t = parent::triplifyString($tid,"rdfs:label",str_replace(array("\"", "'"), array("","\\\'"), stripslashes($a[1]))." [$tid]");
					$label = str_replace(array("\"", "'"), array("","\\\'"), stripslashes($a[1]));
					$t = parent::describeIndividual($tid,$label,"owl:Class",$label);
					$t .= parent::triplify($tid,"rdfs:isDefinedBy",$ouri);					
					$min .= $t;
					$buf .= $t;
					
				} else if($a[0] == "def") {
					$t = str_replace(array("'", "\"", "\\","\\\'"), array("\\\'", "", "",""), $a[1]);
					$min .= parent::triplifyString($tid,"dc:description",$t);
					$buf .= parent::triplifyString($tid,"dc:description",$t);
					
				} else if($a[0] == "property_value") {
					$b = explode(" ",$a[1]);
					$buf .= parent::triplifyString($tid,"obo_vocabulary:".strtolower($b[0]),str_replace("\"", "", strtolower($b[1])));
				} else if($a[0] == "xref") {
				// http://upload.wikimedia.org/wikipedia/commons/3/34/Anatomical_Directions_and_Axes.JPG
				// Medical Dictionary:http\://www.medterms.com/
				// KEGG COMPOUND:C02788 "KEGG COMPOUND"
				// id-validation-regexp:\"REACT_[0-9\]\{1\,4}\\.[0-9\]\{1\,3}|[0-9\]+\"
				//$a[1] = 'id-validation-regexp:\"REACT_[0-9\]\{1\,4}\\.[0-9\]\{1\,3}|[0-9\]+\"';
					if(substr($a[1],0,4) == "http") {
						// http://identifiers.org/hgnc/16982 {source="GARD:0006963"}
						$url = preg_replace("/{.*\}/","",$a[1]);
						$buf .= parent::triplify($tid,"rdfs:seeAlso", str_replace( array(" ",'"wiki"',"\\"), array("+","",""), $url));
					} else {
						$b = explode(":",$a[1],2);
						if(isset($b[1])) {
							if(substr($b[1],0,4) == "http") {
								// https://en.wikipedia.org/wiki/Prolamin {source="SUBMITTER"}
								$url = preg_replace("/{.*\}/","",$b[1]);
								$url = str_replace(array("http\:","https\:"), "http:", $url);
								#echo $url.PHP_EOL;
								$buf .= parent::triplify($tid,"rdfs:seeAlso", parent::makeSafeIRI($url));
							} else {
								$ns = str_replace(array(" ","\\",) ,"",strtolower($b[0]));
								$id = trim($b[1]);
															
								// there may be a comment to remove
								if(FALSE !== ($pos = strrpos($id,' "'))) {
									$comment = substr($id,$pos+1,-1);
									$id = substr($id,0,$pos);
								}
								$id = stripslashes($id);
								// there may be a source statement to remove
								#echo $id.PHP_EOL;
								$id = preg_replace("/\{.*\}/","",$id);
								if($id == '1001251"}') continue;

								if($ns == "pmid") {
									$ns = "pubmed";
									$y = explode(" ",$id);
									$id = $y[0];
								}
								if($ns == "xx") continue;
								if($ns == "icd9cm") {
									$y = explode(" ",$id);
									$id = $y[0];
								}
								if($ns == "xref; umls_cui") continue; 
								if($ns == "url") continue;
								if($ns == "search-url") continue;
								if($ns == "id-validation-regex" or $ns == "regexp") continue;
								if($ns == "submitter") $ns = "chebi.submitter";
								if($ns == "wikipedia" || $ns == "mesh") $id = str_replace(" ","+",$id);
								if($ns == "id-validation-regexp") {
									$buf .= parent::triplifyString($tid,"obo_vocabulary:$ns", $id);
								} else {
									if($ns) {
										// orphanet107{source="efo:1001251"}
										$id = str_replace(array(" ",",","#","<",">"),array("%20","%2C","%23","%3C","%3E"),$id);
										$buf .= parent::triplify($tid,"obo_vocabulary:x-$ns", "$ns:".parent::safeLiteral($id));
									}
								}
							}
						}
					}
				} else if($a[0] == "synonym") {
					// synonym: "entidades moleculares" RELATED [IUPAC:]
					// synonym: "molecular entity" EXACT IUPAC_NAME [IUPAC:]
					// synonym: "Chondrococcus macrosporus" RELATED synonym [NCBITaxonRef:Krzemieniewska_and_Krzemieniewski_1926]
					// synonym: "cerebral amyloid angiopathy, ITM2B-Related, type 2" EXACT [MONDORULE:1, OMIM:117300]
					//grab string inside double quotes			
					preg_match('/"(.*)"(.*)/', $a[1], $matches);
					
					if(!empty($matches)){
						$a[1] = str_replace(array("\\", "\"", "'"),array("", "", "\\\'"), $matches[1].$matches[2]);
					} else {
						$a[1] = str_replace(array("\"", "'"), array("", "\\\'"), $a[1]);
					}
					
					$rel = "SYNONYM";
					$list = array("EXACT","BROAD","RELATED","NARROW");
					$found = false;
					foreach($list AS $keyword) {
					  // get everything after the keyword up until the bracket [
					  if(FALSE !== ($k_pos = strrpos($a[1],$keyword))) {
						$str_len = strlen($a[1]);
						$keyword_len = strlen($keyword);
						$keyword_end_pos = $k_pos+$keyword_len;
						$b1_pos = strrpos($a[1],"[");
						$b2_pos = strrpos($a[1],"]");					
						$b_text = substr($a[1],$b1_pos+1,$b2_pos-$b1_pos-1);					
						$diff = $b1_pos-$keyword_end_pos-1;
						if($diff != 0) {
							// then there is more stuff here
							$k = substr($a[1],$keyword_end_pos+1,$diff);
							$rel = trim($k);
							if($tid == "mondo:0008484") continue; # error in parsing of synonym type as it occurs capitalised in the term...
						} else {
							// create the long predicate
							$rel = $keyword."_SYNONYM";
						}
						$found=true;
						$str = substr($a[1],0,$k_pos-1);
						break;
					   }
					}

					// check to see if we still haven't found anything
					if($found === false) {
						// we didn't find one of the keywords
						// so take from the start to the bracket
						$b1_pos = strrpos($a[1],"[");
						$str = substr($a[1],0,$b1_pos-1);
					} 

					#$rel = str_replace(" ","_",$rel);
					// $lit = addslashes($str.($b_text?" [".$b_text."]":""));
					// synonym: "cerebral amyloid angiopathy, ITM2B-Related, type 2" EXACT [MONDORULE:1, OMIM:117300]
					$l = parent::triplifyString($tid,"obo_vocabulary:".strtolower($rel), parent::safeLiteral($str));
					$buf .= $l;
					
				} else if($a[0] == "alt_id") {
					parent::getRegistry()->parseQname($a[1],$ns,$id);
					if($id != 'curators') {
						$buf .= parent::triplify("$ns:$id","rdfs:seeAlso",stripslashes($tid));
					}
					
				} else if($a[0] == "is_a") {
					// do subclassing
					// is_a: MONDO:0000640 {source="DOID:3870", source="MONDO:Redundant", source="MONDOLEX:0002798", source="NCIT:C5961"} ! central nervous system primitive neuroectodermal neoplasm
					$url = preg_replace("/{.*\}/","",$a[1]);
					parent::getRegistry()->parseQName($url,$ns,$id);
					if(trim($ns) == '') $ns = "unspecified";
					$t = parent::triplify($tid,"rdfs:subClassOf","$ns:$id");
					$buf .= $t;
					$min .= $t;
					$is_a = true;
					
				} else if($a[0] == "intersection_of") {
					if(!isset($intersection_of)) {
						// $intersection_of = '<'.parent::getRegistry()->getFQURI($tid).'> <'.parent::getRegistry()->getFQURI('owl:equivalentClass').'> [<'.parent::getRegistry()->getFQURI('rdf:type').'> <'.parent::getRegistry()->getFQURI('owl:Class').'>; <'.parent::getRegistry()->getFQURI('owl:intersectionOf').'> (';
						$intersection_of = '<'.parent::getRegistry()->getFQURI($tid).'> <'.parent::getRegistry()->getFQURI('owl:equivalentClass').'> _:b'.(++$bid)." $graph_uri .".PHP_EOL;
						$intersection_of .= '_:b'.$bid.' <'.parent::getRegistry()->getFQURI('rdf:type').'> <'.parent::getRegistry()->getFQURI('owl:Class')."> $graph_uri .".PHP_EOL;
						$intersection_of .= '_:b'.$bid.' <'.parent::getRegistry()->getFQURI('owl:intersectionOf').'> _:b'.(++$bid)." $graph_uri .".PHP_EOL;
					}
					
					/*
					intersection_of: ECO:0000206 ! BLAST evidence
					intersection_of: develops_from VAO:0000092 ! chondrogenic condensation
					intersection_of: OBO_REL:has_part VAO:0000040 ! cartilage tissue
					*/
					$c = explode(" ",$a[1]);
					if(count($c) == 1) { // just a class					
						parent::getRegistry()->parseQName($c[0],$ns,$id);
						$intersection_of .= '_:b'.$bid.' <'.parent::getRegistry()->getFQURI('rdfs:subClassOf').'> <'.parent::getRegistry()->getFQURI("$ns:$id")."> $graph_uri .".PHP_EOL;
						$buf .= parent::triplify($tid,"rdfs:subClassOf","$ns:$id");
					} else if(count($c) == 2) { // an expression						
						parent::getRegistry()->parseQName($c[0],$pred_ns,$pred_id);
						parent::getRegistry()->parseQName($c[1],$obj_ns,$obj_id);
						
						$intersection_of .= '_:b'.$bid.' <'.parent::getRegistry()->getFQURI('owl:onProperty').'> <'.parent::getRegistry()->getFQURI("obo_vocabulary:".$pred_id)."> $graph_uri .".PHP_EOL;
						$intersection_of .= '_:b'.$bid.' <'.parent::getRegistry()->getFQURI('owl:someValuesFrom').'> <'.parent::getRegistry()->getFQURI("$obj_ns:$obj_id").">  $graph_uri .".PHP_EOL;
						
						$buf .= parent::triplify($tid,"obo_vocabulary:$pred_id","$obj_ns:$obj_id");
					}

				} else if ($a[0] == "relationship") {
					if(!isset($relationship)) {
						$relationship = '<'.parent::getRegistry()->getFQURI($tid).'> <'.parent::getRegistry()->getFQURI('rdfs:subClassOf').'> _:b'.(++$bid)." $graph_uri .".PHP_EOL;
						$relationship .= '_:b'.$bid.' <'.parent::getRegistry()->getFQURI('rdf:type').'> <'.parent::getRegistry()->getFQURI('owl:Class')."> $graph_uri .".PHP_EOL;
						$relationship .= '_:b'.$bid.' <'.parent::getRegistry()->getFQURI('owl:intersectionOf').'> _:b'.(++$bid)." $graph_uri .".PHP_EOL;
					}
					
					/*
					relationship: develops_from VAO:0000092 ! chondrogenic condensation
					relationship: OBO_REL:has_part VAO:0000040 ! cartilage tissue
					*/
					$c = explode(" ",$a[1]);
					if(count($c) == 1) { // just a class	
						parent::getRegistry()->parseQName($c[0],$ns,$id);
						if(trim($ns) == '') $ns = "unspecified";
						$relationship .= parent::getRegistry()->getFQURI("$ns:$id");
						$buf .= parent::triplify($tid,"rdfs:subClassOf","$ns:$id");

					} else if(count($c) == 2) { // an expression						
						parent::getRegistry()->parseQName($c[0],$pred_ns,$pred_id);
						parent::getRegistry()->parseQName($c[1],$obj_ns,$obj_id);
						if(trim($obj_ns) == '') $obj_ns = "unspecified";

						$relationship .= '_:b'.$bid.' <'.parent::getRegistry()->getFQURI('owl:onProperty').'> <'.parent::getRegistry()->getFQURI("obo_vocabulary:".$pred_id).">  $graph_uri .".PHP_EOL;
						$relationship .= '_:b'.$bid.' <'.parent::getRegistry()->getFQURI('owl:someValuesFrom').'> <'.parent::getRegistry()->getFQURI("$obj_ns:$obj_id")."> $graph_uri .".PHP_EOL;
		
						$buf .= parent::triplify($tid,"obo_vocabulary:$pred_id","$obj_ns:$obj_id"); #@todo this causes problem with OGG-MM
					}
				} else {
					// default handler
					if(isset($a[1])) $buf .= parent::triplifyString($tid,"obo_vocabulary:$a[0]", str_replace(array("\"", "'"), array("", "\\\'") ,stripslashes($a[1])));
				}
			} else {
				//header
				//format-version: 1.0
				$buf .= parent::triplifyString($ouri,"obo_vocabulary:$a[0]",
					str_replace( array('\:'), array(':'), isset($a[1])?$a[1]:""));
			}

			if($minimal || $minimalp) parent::getWriteFile()->write($min);
			else parent::getWriteFile()->write($buf);

			$min = '';$buf ='';$header='';
		}
		//if(isset($intersection_of))  $buf .= $intersection_of.")].".PHP_EOL;
		//if(isset($relationship))  $buf .= $relationship.")].".PHP_EOL;

		if($minimal || $minimalp) parent::getWriteFile()->Write($min);
		else parent::getWriteFile()->write($buf);
	}
}

?>
