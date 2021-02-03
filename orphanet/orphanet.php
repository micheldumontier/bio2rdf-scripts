<?php
/**
Copyright (C) 2013 Michel Dumontier

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


/**
 * An RDF generator for orphanet
 * documentation: http://www.orphadata.org/
 * @version 1.0
 * @author Michel Dumontier
*/

require_once(__DIR__.'/../../php-lib/bio2rdfapi.php');

class ORPHANETParser extends Bio2RDFizer 
{
	private $filemap = array(
		'disease' => 'en_product1.xml',
		'prevalence'     => 'en_product9_prev.xml',
		'phenotypefreq' => 'en_product4.xml',
		'genes'   => 'en_product6.xml'
	);
	function __construct($argv) {
		parent::__construct($argv, "orphanet");
		parent::addParameter('files',true,'all|disease|genes|phenotypefreq|prevalence','all','all or comma-separated list of ontology short names to process');
		parent::addParameter('download_url',false,null,'http://www.orphadata.org/data/xml/');
		parent::initialize();
	}

	function run() 
	{
		$ldir = parent::getParameterValue('indir');
		$odir = parent::getParameterValue('outdir');
		$dd = '';
		
		$files = parent::getParameterValue('files');
		if($files == 'all') {
			$files = explode('|', parent::getParameterList('files'));
			array_shift($files);
		} else {
			$files = explode(',', parent::getParameterValue('files'));
		}
		
		foreach($files AS $file) {
			echo "processing $file ...";
			$lfile = $ldir.$this->filemap[$file];
			$rfile = parent::getParameterValue('download_url').$this->filemap[$file];
			if(!file_exists($lfile) || parent::getParameterValue('download') == 'true') {
				$ret = utils::downloadSingle($rfile,$lfile);
				if($ret === false) {
					echo "unable to download $file ... skipping".PHP_EOL;
					continue;
				}
			}
			
			parent::setReadFile($lfile,true);	
			
			$suffix = parent::getParameterValue('output_format');
			$ofile = "orphanet-".$file.'.'.$suffix; 
			$gz = strstr(parent::getParameterValue('output_format'), "gz")?($gz=true):($gz=false);
			
			parent::setWriteFile($odir.$ofile, $gz);
			$this->$file($lfile);
			parent::getWriteFile()->close();
			parent::getReadFile()->close();
			parent::clear();
			echo "done!".PHP_EOL;

			// dataset description
			$source_file = (new DataResource($this))
				->setURI($rfile)
				->setTitle("Orphanet: $file")
				->setRetrievedDate(parent::getDate(filemtime($lfile)))
				->setFormat("application/xml")
				->setPublisher("http://www.orpha.net")
				->setHomepage("http://www.orpha.net/")
				->setRights("use")
				->setRights("sharing-modified-version-needs-permission")
				->setLicense("http://creativecommons.org/licenses/by-nd/3.0/")
				->setDataset("http://identifiers.org/orphanet/");

			$prefix = parent::getPrefix();
			$bVersion = parent::getParameterValue('bio2rdf_release');
			$date = parent::getDate(filemtime($odir.$ofile));

			$output_file = (new DataResource($this))
				->setURI("http://download.bio2rdf.org/release/$bVersion/$prefix/$ofile")
				->setTitle("Bio2RDF v$bVersion RDF version of $prefix")
				->setSource($source_file->getURI())
				->setCreator("https://github.com/bio2rdf/bio2rdf-scripts/blob/master/orphanet/orphanet.php")
				->setCreateDate($date)
				->setHomepage("http://download.bio2rdf.org/release/$bVersion/$prefix/$prefix.html")
				->setPublisher("http://bio2rdf.org")
				->setRights("use-share-modify")
				->setRights("by-attribution")
				->setRights("restricted-by-source-license")
				->setLicense("http://creativecommons.org/licenses/by/3.0/")
				->setDataset(parent::getDatasetURI());

			$gz = (strstr(parent::getParameterValue('output_format'),".gz") === FALSE)?false:true;
			if($gz) $output_file->setFormat("application/gzip");
			if(strstr(parent::getParameterValue('output_format'),"nt")) $output_file->setFormat("application/n-triples");
			else $output_file->setFormat("application/n-quads");

			$dd .= $source_file->toRDF().$output_file->toRDF();

		}//foreach
		parent::writeToReleaseFile($dd);
	}

	function disease($file)
	{
		$xml = new CXML($file);
		while($xml->parse("DisorderList") == TRUE) {
			$x = $xml->GetXMLRoot();
			$version = $x->attributes()->version;
			
			foreach($x->Disorder AS $d) {
				$internal_id = (string) $d->attributes()->id;
				$orphanet_id = parent::getNamespace().((string)$d->OrphaCode);
				$name = (string) $d->Name;
				$expert_link = (string) $d->ExpertLink;
				
				parent::addRDF(
					parent::describeIndividual($orphanet_id,$name,parent::getVoc()."Disorder").
					parent::describeClass(parent::getVoc()."Disorder","Disorder").
					parent::triplifyString($orphanet_id, parent::getVoc()."internal-id", $internal_id).
					parent::triplify($orphanet_id, parent::getVoc()."expert-link-url", $expert_link)
				);

				// get the synonyms
				foreach($d->SynonymList AS $s) {
					$synonym = str_replace('"','', (string) $s->Synonym);
					parent::addRDF(
						parent::triplifyString($orphanet_id, parent::getVoc()."synonym", $synonym)
					);
				}
				//DisorderFlagList
				foreach($d->DisorderFlagList AS $dfl) {
					$df = $dfl->DisorderFlag;
					if($df) {
						parent::addRDF(
							parent::triplifyString($orphanet_id, parent::getVoc()."disorder-flag", (string) $df->attributes()->id)
						);
					}
				}
				// get external references
				foreach($d->ExternalReferenceList AS $erl) {
					foreach($erl->ExternalReference AS $er) {						
						$source = (string) $er->Source;
						$db = parent::getRegistry()->getPreferredPrefix($source);
						$id = (string) $er->Reference;
						parent::addRDF(
							parent::triplify($orphanet_id, parent::getVoc()."x-$db", "$db:$id")
						);
					}
				}
				// get the definition
				foreach($d->TextualInformationList AS $til) {
					foreach($til->TextualInformation As $ti) {
						foreach($ti->TextSectionList AS $tsl) {
							foreach($tsl->TextSection AS $ts) {
								if(((string) $ts->TextSectionType->Name) == "Definition") {
									parent::addRDF(
										parent::triplifyString($orphanet_id, parent::getVoc()."definition", addslashes((string) $ts->Contents))
									);
								};								
							}
						}
					}
				}
				parent::writeRDFBufferToWriteFile();
			}
		}
		unset($xml);	
	}
	
	function prevalence ($file) 
	{
		$seen = '';
		$xml = new CXML($file);
		while($xml->parse("DisorderList") == TRUE) {
			$x = $xml->GetXMLRoot();
			foreach($x->Disorder AS $d) {
				#var_dump($x);exit;
				$orphanet_id = parent::getNamespace().((string)$d->OrphaCode);
				$disease_name = (string) $d->Name;

				foreach($d->PrevalenceList->Prevalence AS $pl) {
					$id = parent::getRes()."pl".((string) $pl->attributes()->id);
					parent::addRDF(
						parent::describeClass($id,"Prevalence",parent::getVoc()."Prevalence").
						parent::describeIndividual($id, "Prevalence for $disease_name", parent::getVoc()."Prevalence")
					);
					$type_id = parent::getRes()."pt".(string) $pl->PrevalenceType->attributes()->id;
					$type_label = (string) $pl->PrevalenceType->Name;
					if($type_label != "") {
						parent::addRDF(
							parent::describeIndividual($type_id, $type_label, parent::getVoc()."Prevalence-Type").
							parent::triplify($id, parent::getVoc()."prevalence-type", $type_id).
							parent::triplify($orphanet_id, parent::getVoc()."prevalence", $id)
						);
					}

					$qual_id = parent::getRes()."qu".(string) $pl->PrevalenceQualification->attributes()->id;
					$qual_label = (string) $pl->PrevalenceQualification->Name;
					if($qual_label != "") {
						parent::addRDF(
							parent::describeIndividual($qual_id, $qual_label, parent::getVoc()."Prevalence-Qualification").
							parent::triplify($id, parent::getVoc()."prevalence-qualification", $qual_id)
						);
					}

					$prev_id = parent::getRes()."pr".(string) $pl->PrevalenceClass->attributes()->id;
					$prev_label = (string) $pl->PrevalenceClass->Name;
					if($prev_label != "") {
						parent::addRDF(
							parent::describeIndividual($prev_id, $prev_label, parent::getVoc()."Prevalence-Value").
							parent::triplify($id, parent::getVoc()."prevalence-value", $prev_id)
						);
					}

					$geo_id = parent::getRes()."geo".(string) $pl->PrevalenceGeographic->attributes()->id;
					$geo_label = (string) $pl->PrevalenceGeographic->Name;
					if($geo_label != "") {
						parent::addRDF(
							parent::describeIndividual($geo_id, $geo_label, parent::getVoc()."Geographic-Prevalence").
							parent::triplify($id, parent::getVoc()."prevalence-geo", $geo_id)
						);
					}

					$val_id = parent::getRes()."val".(string) $pl->PrevalenceValidationStatus->attributes()->id;
					$val_label = (string) $pl->PrevalenceValidationStatus->Name;
					if($val_label != "") {
						parent::addRDF(
							parent::describeIndividual($val_id, $val_label, parent::getVoc()."Prevalence-Validation-Status").
							parent::triplify($id, parent::getVoc()."prevalence-status", $val_id)
						);
					}
					$valmoy =  (string) $pl->ValMoy;
					if($valmoy != "") {
						parent::addRDF(
								parent::triplifyString($id, parent::getVoc()."val-moy", $valmoy)
						);
					}

					
					$source = trim((string) $pl->Source);
					if($source and (strlen($source) != 0)) {
						//23712425[PMID]
						preg_match_all("/([0-9]*)\[([^\]]*)?\]/",$source, $m, PREG_SET_ORDER );
						foreach($m AS $i) {
							if(isset($i[2]) and ($i[2] == "PMID")) {
								$source_id = "PMID:".$i[1];
								parent::addRDF(
									parent::triplify($id, parent::getVoc()."source", $source_id)
								);
							} else {
								parent::addRDF(
									parent::triplifyString($id, parent::getVoc()."source", $i[0])
								);
							}

						}
					
					}
				}
				parent::writeRDFBufferToWriteFile();
			}
		}
		unset($xml);	
	}
	
	function onset ($file) 
	{
		$seen = '';
		$xml = new CXML($file);
		while($xml->parse("DisorderList") == TRUE) {
			$x = $xml->GetXMLRoot();
			foreach($x->Disorder AS $d) {
				var_dump($d);exit;
				$orphanet_id = parent::getNamespace().((string)$d->OrphaCode);
				$disease_name = (string) $d->Name;
				foreach($d->PrevalanceList AS $pl) {
					$id = parent::getNamespace().((string) $pl->attributes()->id);

					parent::addRDF(
						parent::triplify($orphanet_id, parent::getVoc()."prevalence", $id).
						parent::describeClass($id,$name,parent::getVoc()."Prevalence")
					);

				}
				if(isset($d->AverageAgeofOnset)) {
					$id = parent::getNamespace().((string) $d->AverageAgeOfOnset->attributes()->id);
					$name = (string) $d->AverageAgeOfOnset->Name;
					parent::addRDF(
						parent::triplify($orphanet_id, parent::getVoc()."average-age-of-onset", $id).
						parent::describeClass($id,$name,parent::getVoc()."Average-Age-Of-Onset")
					);
				}
				if(isset($d->AverageAgeofDeath)) {
					$id = parent::getNamespace().((string) $d->AverageAgeofDeath->attributes()->id);
					$name = (string) $d->AverageAgeOfDeath->Name;
					parent::addRDF(
						parent::triplify($orphanet_id, parent::getVoc()."average-age-of-death", $id).
						parent::describeClass($id,$name,parent::getVoc()."Average-Age-Of-Death")
					);
				}
				if(isset($d->TypeOfInheritanceList)) {
					if($d->TypeOfInheritanceList->attributes()) {
						$n = $d->TypeOfInheritanceList->attributes()->count;
						if($n > 0) {
							foreach($d->TypeOfInheritanceList AS $o) {
								//echo $orphanet_id.PHP_EOL;
								$toi = $o->TypeOfInheritance;
								$id = parent::getNamespace().((string) $toi->attributes()->id);
								$name = (string) $toi->Name;
								parent::addRDF(
									parent::triplify($orphanet_id, parent::getVoc()."type-of-inheritance", $id)
									.parent::describeClass($id,$name,parent::getVoc()."Inheritance")
								);
							}
						}
					}
				}
//				echo $this->getRDF();exit;
				parent::writeRDFBufferToWriteFile();
			}
		}
		unset($xml);	
	}


	function phenotypefreq($file)
	{
	/*
	<HPODisorderSetStatus id="51">
      <Disorder id="109">
        <OrphaNumber>558</OrphaNumber>
        <ExpertLink lang="en">http://www.orpha.net/consor/cgi-bin/OC_Exp.php?lng=en&amp;Expert=558</ExpertLink>
        <Name lang="en">Marfan syndrome</Name>
        <DisorderType id="21394">
          <Name lang="en">Disease</Name>
        </DisorderType>
        <DisorderGroup id="36547">
          <Name lang="en">Disorder</Name>
		</DisorderGroup>
		
        <HPODisorderAssociationList count="59">
          <HPODisorderAssociation id="207760">
            <HPO id="104">
              <HPOId>HP:0000768</HPOId>
              <HPOTerm>Pectus carinatum</HPOTerm>
            </HPO>
            <HPOFrequency id="28412">
              <Name lang="en">Very frequent (99-80%)</Name>
            </HPOFrequency>
            <DiagnosticCriteria id="28454">
              <Name lang="en">Diagnostic criterion</Name>
            </DiagnosticCriteria>
		  </HPODisorderAssociation>
		  */
		$xml = new CXML($file);
		while($xml->parse("HPODisorderSetStatus") == TRUE) {
			$x = $xml->GetXMLRoot();
			foreach($x->Disorder AS $d) {
				$orphanet_id = parent::getNamespace().((string)$d->OrphaCode);
				$disease_name = ((string)$d->Name);
				foreach($d->HPODisorderAssociationList->HPODisorderAssociation AS $ds) {
					$sfid = parent::getRes()."sf".((string)$ds->attributes()->id);
					$s = (string) $ds->HPO->HPOTerm;
					$sid = $ds->HPO->HPOId;
					$f = (string) $ds->HPOFrequency->Name;
					$fid = parent::getRes()."f".((string) $ds->HPOFrequency->attributes()->id);

					$diagnostic = false;
					if($ds->DiagnosticCriteria->Name) {
						$diagnostic = true;
					}
					$sflabel =  "$f $s".(($diagnostic == true)?" that is diagnostic":"")." for ".$disease_name;

					parent::addRDF(
						parent::describeIndividual($sfid, $sflabel, parent::getVoc()."Clinical-Sign-And-Frequency").
						parent::describeClass(parent::getVoc()."Clinical-Sign-And-Frequency","Clinical Sign and Frequency").
						parent::triplify($orphanet_id, parent::getVoc()."sign-freq", $sfid).
						parent::triplify($sfid,parent::getVoc()."sign", $sid).
						parent::triplify($sfid,parent::getVoc()."frequency",$fid).
						parent::triplifyString($sfid, parent::getVoc()."is-diagnostic", (isset($diagnostic)?"true":"false")).
						parent::triplifyString($fid, "rdfs:label", $fid).
						parent::describeClass($fid,$f,parent::getVoc()."Frequency")
					);
				}
				parent::writeRDFBufferToWriteFile();
			}
		}
		unset($xml);
	}
	
	function signs($file) 
	{
	/*
	<ClinicalSign id="49580">
      <Name lang="en">Oligoelements metabolism anomalies</Name>
      <ClinicalSignChildList count="0">
      </ClinicalSignChildList>
    </ClinicalSign>
    <ClinicalSign id="25300">
      <Name lang="en">Abnormal toenails</Name>
      <ClinicalSignChildList count="4">
        <ClinicalSign id="25350">
          <Name lang="en">Absent/small toenails/anonychia of feet</Name>
          <ClinicalSignChildList count="0">
          </ClinicalSignChildList>
        </ClinicalSign>
*/
		$xml = new CXML($file);
		while($xml->parse("ClinicalSignList") == TRUE) {
			$x = $xml->GetXMLRoot();
			foreach($x->ClinicalSign AS $cs) {
				$this->traverseCS($cs);
				parent::writeRDFBufferToWriteFile();
			}
		}
		unset($xml);
	}

	function traverseCS($cs)
	{
		if($cs->ClinicalSignChildList->attributes()->count > 0) {
			$cs_id = parent::getVoc().((string)$cs->attributes()->id);
			$cs_label = (string)$cs->Name;
			parent::addRDF(
				parent::describeClass($cs_id,$cs_label,parent::getVoc()."Clinical-Sign")
			);

			foreach($cs->ClinicalSignChildList->ClinicalSign AS $cl) {
				$child_id = parent::getVoc().((string)$cl->attributes()->id);
				$child_label = (string) $cl->Name;
				parent::addRDF(
					parent::describeClass($child_id,$child_label,$cs_id)
				);
				if(isset($cl->ClinicalSignChildList)) $this->traverseCS($cl);
			}
		}
	}

	function genes($file)
	{
		$xml = new CXML($file);
		while($xml->parse("DisorderList") == TRUE) {
			$x = $xml->GetXMLRoot();
			foreach($x->Disorder AS $d) {
				$orphanet_id = parent::getNamespace().((string)$d->OrphaCode);
				$disorder_name = (string) $d->Name;

				foreach($d->DisorderGeneAssociationList->DisorderGeneAssociation AS $dga) {
					// gene
					$gene = $dga->Gene;
					$gid = ((string) $gene->attributes()->id);		
					$gene_id = parent::getNamespace().$gid;
					$gene_label = (string) $gene->Name;
					$gene_symbol = (string) $gene->Symbol;
					parent::addRDF(
						parent::describeIndividual($gene_id,$gene_label,parent::getVoc()."Gene").
						parent::describeClass(parent::getVoc()."Gene","Orphanet Gene").
						parent::triplifyString($gene_id,parent::getVoc()."symbol",$gene_symbol)
					);

					foreach($gene->SynonymList AS $s) {
						$synonym = (string) $s->Synonym;
						parent::addRDF(
							parent::triplifyString($gene_id,parent::getVoc()."synonym",$synonym)
						);
					}
					foreach($gene->ExternalReferenceList AS $erl) {
						foreach($erl->ExternalReference AS $er) {
							$db = (string) $er->Source;
							$db = parent::getRegistry()->getPreferredPrefix($db);
							$id = (string) $er->Reference;
							$xref = "$db:$id";
							parent::addRDF(
								parent::triplify($gene_id, parent::getVoc()."x-$db", $xref)
							);
						}
					}

					// parse the sources of validation
					//<SourceOfValidation>16150725[PMID]_16150725[PMID]_21771795[PMID]</SourceOfValidation>
					$sources = explode("_",$dga->SourceOfValidation);
					foreach($sources AS $source) {
						preg_match_all("/([0-9]*)\[([^\]]*)?\]/",$source, $m, PREG_PATTERN_ORDER );
						if(isset($m[1][0])) {
							$prefix = parent::getRegistry()->getPreferredPrefix($m[2][0]);
							parent::addRDF(
								parent::triplify($gene_id,parent::getVoc()."source-of-validation", "$prefix:".$m[1][0])
							);
						}
					}

					$dga_id = parent::getRes().((string)$d->OrphaNumber)."_".md5($dga->asXML());
					$ga = $dga->DisorderGeneAssociationType;
					$ga_id    = parent::getRes()."ga".((string) $ga->attributes()->id);
					$ga_label = (string) $ga->Name;

					$s = $dga->DisorderGeneAssociationStatus;
					$s_id    = parent::getRes()."st".((string) $s->attributes()->id);
					$s_label = (string) $s->Name;

					parent::addRDF(
						parent::describeIndividual($dga_id,"$ga_label $gene_label in $disorder_name ($s_label)",$ga_id).
						parent::describeClass($ga_id,$ga_label,parent::getVoc()."Disorder-Gene-Association").
						parent::triplify($dga_id,parent::getVoc()."status", $s_id).
						parent::describeClass($s_id,$s_label,parent::getVoc()."Disorder-Gene-Association-Status").
						parent::triplify($dga_id,parent::getVoc()."disorder",$orphanet_id).
						parent::describeIndividual($orphanet_id,$disorder_name,parent::getVoc()."Disorder").
						parent::triplify($dga_id,parent::getVoc()."gene",$gene_id)
					);
				}

				parent::writeRDFBufferToWriteFile();
			}
		}
		unset($xml);
	}
}
?>
