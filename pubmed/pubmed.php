<?php
/**
Copyright (C) 2013 Alison Callahan, Michel Dumontier

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
 * An RDF generator for PubMed (http://ncbi.nlm.nih.gov/pubmed)
 * @version 2.1
 * @author Alison Callahan
 * @author Michel Dumontier
*/

require_once(__DIR__.'/../../php-lib/bio2rdfapi.php');

class PubmedParser extends Bio2RDFizer
{
	function __construct($argv) {
		parent::__construct($argv, "pubmed");
		parent::addParameter('files',true,'all','all','files to process');
		parent::addParameter('version',false,null,'2013','date version of files');
		parent::initialize();
	}//constructor

	function run() {

		if(parent::getParameterValue('process') === true){
			$this->process_dir();
		}
	}//run

	function process_dir(){
		$this->setCheckPoint('dataset');

		$ldir = parent::getParameterValue('indir');
		$odir = parent::getParameterValue('outdir');

		$graph_uri = parent::getGraphURI();

		$dataset_description = '';

		$gz = (strstr(parent::getParameterValue('output_format'),".gz") === FALSE)?false:true;

		//set graph URI to dataset graph
		if(parent::getParameterValue('dataset_graph') == true) parent::setGraphURI(parent::getDatasetURI());

		$files = glob($ldir."*.xml.gz");
		foreach($files AS $i => $file) {
			echo "Processing $file (".($i+1)."/".count($files).") ...";
			$this->process_file($file);
			parent::clear();
			echo "done!".PHP_EOL;
		}

		$source_file = (new DataResource($this))
			->setURI("http://www.ncbi.nlm.nih.gov/pubmed")
			->setTitle("NCBI PubMed")
			->setRetrievedDate( date ("Y-m-d\TG:i:s\Z", filemtime($ldir)))
			->setFormat("text/xml")
			->setPublisher("http://ncbi.nlm.nih.gov/")
			->setHomepage("http://www.ncbi.nlm.nih.gov/pubmed/")
			->setRights("use-share-modify")
			->setLicense("http://www.nlm.nih.gov/databases/license/license.html")
			->setDataset("http://identifiers.org/pubmed/");

		$prefix = parent::getPrefix();
		$bVersion = parent::getParameterValue('bio2rdf_release');
		$date = date ("Y-m-d\TG:i:s\Z");
		$output_file = (new DataResource($this))
			->setURI("http://download.bio2rdf.org/release/$bVersion/$prefix")
			->setTitle("Bio2RDF v$bVersion RDF version of $prefix (generated at $date)")
			->setSource($source_file->getURI())
			->setCreator("https://github.com/bio2rdf/bio2rdf-scripts/blob/master/pubmed/pubmed.php")
			->setCreateDate($date)
			->setHomepage("http://download.bio2rdf.org/release/$bVersion/$prefix/$prefix.html")
			->setPublisher("http://bio2rdf.org")
			->setRights("use-share-modify")
			->setRights("by-attribution")
			->setRights("restricted-by-source-license")
			->setLicense("http://creativecommons.org/licenses/by/3.0/")
			->setDataset(parent::getDatasetURI());

		if($gz) $output_file->setFormat("application/gzip");
		if(strstr(parent::getParameterValue('output_format'),"nt")) $output_file->setFormat("application/n-triples");
		else $output_file->setFormat("application/n-quads");

		$dataset_description .= $source_file->toRDF().$output_file->toRDF();

		//set graph URI back to default
		parent::setGraphURI($graph_uri);

		// write the dataset description
		$this->setWriteFile($odir.$this->getBio2RDFReleaseFile());
		$this->getWriteFile()->write($dataset_description);
		$this->getWriteFile()->close();
	}//process)dir

	function process_file($infile){

		$odir = parent::getParameterValue('outdir');

		$suffix = parent::getParameterValue('output_format');
		$ofile = $odir.basename($infile, ".xml.gz").'.'.$suffix;

		$gz = (strstr(parent::getParameterValue('output_format'),".gz") === FALSE)?false:true;

		$fp = gzopen($infile, "r") or die("Could not open file ".$infile."!\n");

		$this->setReadFile($infile);

		$this->getReadFile()->setFilePointer($fp);

		$this->setWriteFile($ofile, $gz);

		$this->setCheckPoint('file');

		$this->pubmed();

		$this->getWriteFile()->close();	
	}

	function getString($str) {
		return utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""),$str));
	}

	function pubmed(){
		//$this->version = "2012";

		$citations = null;
		$ext = substr(strrchr($this->GetReadFile()->GetFileName(), '.'), 1);

		if($ext = "gz"){
			$citations = new SimpleXMLElement("compress.zlib://".$this->GetReadFile()->GetFileName(), NULL, TRUE);
		} elseif($ext="xml"){
			$citations = new SimpleXMLElement($this->GetReadFile()->GetFileName(), NULL, TRUE);
		}

		foreach($citations->MedlineCitation as $citation){
			$this->setCheckPoint('record');

			$pmid = $citation->PMID;
			$dateCreated = $citation->DateCreated;
			$dateCompleted = $citation->DateCompleted;
			$dateRevised = $citation->DateRevised;
			$chemicals = $citation->ChemicalList;//optional
			$supplMeshList = $citation->SupplMeshList;//optional
			$commentsCorrectionsList = $citation->CommentCorrectionsList;//optional
			$geneSymbolList = $citation->GeneSymbolList; //optional; if present, children are <GeneSymbol>
			$meshHeadingList = $citation->MeshHeadingList; //optional
			$numberOfReferences = $citation->NumberOfReferences; //optional
			$personalNameSubjectList = $citation->PersonalNameSubjectList;//optional
			$keywordList = $citation->KeywordList;//optional
			$generalNote = $citation->GeneralNote;//optional
			$investigatorList = $citation->InvestigatorList;//optional

			$citationOwner = $citation['Owner'];
			$citationStatus = $citation['Status'];
			$citationVersionID = $citation['VersionID'];
			$citationVersionDate = $citation['VersionDate'];

			$publicationTypeList = $citation->Article->PublicationTypeList; //children are <PublicationType>
			$articleTitle = $citation->Article->ArticleTitle;
			$abstract = $citation->Article->Abstract;//optional
			$dataBankList = $citation->Article->DataBankList;//optional
			$grantList = $citation->Article->GrantList;//optional

			$affiliation = $citation->Article->Affiliation;//optional

			$vernacularTitle = $citation->Article->VernacularTitle; //optional
			$copyright = $citation->Article->Abstract->CopyrightInformation;//optional
			$articleDate = $citation->Article->ArticleDate;//optional
			$authorList = $citation->Article->AuthorList;//optional

			$journal = $citation->Article->Journal;
			$pagination = trim($citation->Article->Pagination);
			$pubmodel = $citation->Article['PubModel'];

			$id = parent::getNamespace().$pmid;
			$label = ($this->getString($articleTitle));
			parent::addRDF(
				parent::describeIndividual($id, $label, parent::getVoc()."PubMedRecord").
				parent::describeClass(parent::getVoc()."PubMedRecord","PubMedRecord").
				parent::triplify($id,"rdfs:seeAlso","http://www.ncbi.nlm.nih.gov/pubmed/$pmid")
			);

			if(!empty($citationOwner)){
				$owner = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $citationOwner));
				parent::addRDF(
					parent::triplifyString($id, parent::getVoc()."owner", $owner)
				);
			}

			if(!empty($citationStatus)){
				$status = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $citationStatus));
				parent::addRDF(
					parent::triplifyString($id, parent::getVoc()."status", $status)
				);
			}

			if(!empty($citationVersionID)){
				$version_id = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $citationVersionID));
				parent::addRDF(
					parent::triplifyString($id, parent::getVoc()."version_id", $version_id)
				);
			}

			if(!empty($citationVersionDate)){
				$this->formatDate($citationVersionDate,"version_date",$id);
			}

			$publication_model = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $pubmodel));
			parent::addRDF(
					parent::triplifyString($id, parent::getVoc()."publication_model", $publication_model)
			);

			foreach($citation->OtherID as $otherID){
				if(!empty($otherID)){
					$other_id = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $otherID));
					$other_id_source = trim($otherID['Source']);
					parent::addRDF(
						parent::triplifyString($id, parent::getVoc()."other_id", $other_id).
						parent::triplifyString($id, parent::getVoc()."other_id_source", $other_id_source)
					);
					if(strstr($other_id,"PMC")) {
						parent::addRDF(parent::triplify($id,parent::getVoc()."x-pmc","pmc:".$other_id));
					}
				}
			}

			if(!empty($dateCreated)){
				$this->formatDate($id,"created_date",$dateCreated);
			}

			if(!empty($dateCompleted)){
				$this->formatDate($id,"completed_date",$dateCompleted);
			}

			if(!empty($dateRevised)){
				$this->formatDate($id,"revised_date", $dateRevised);
			}

			foreach($publicationTypeList->PublicationType as $publicationType){
				$publication_type = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $publicationType));
				$publication_type_id = parent::getVoc().str_replace(" ","-",$publication_type);
				parent::addRDF(
					parent::triplify($id, parent::getVoc()."publication_type", $publication_type_id).
					parent::describeClass(parent::getVoc().$publication_type_id,$publication_type)
				);
			}

			if(!empty($abstract)){
				$abstractIdentifier = parent::getRes().$pmid."_ABSTRACT";
				$abstractLabel = "Abstract for ".parent::getVoc().$pmid;
				parent::addRDF(
					parent::describeIndividual($abstractIdentifier, $abstractLabel, parent::getVoc()."Article-Abstract").
					parent::describeClass(parent::getVoc()."Article-Abstract","Article Abstract").
					parent::triplify($id, "dc:abstract", $abstractIdentifier)
				);

				$abstractText = "";
				foreach($abstract->AbstractText as $text){
					$abstractText .= " ".$text;
					if(!empty($text['Label']) && $text['Label'] !== "UNLABELLED"){
						$nlmCategory = utf8_encode(str_replace("\"", "", $text['NlmCategory']));
						$text_string = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $text));
						parent::addRDF(
							parent::triplifyString($abstractIdentifier, parent::getVoc()."abstract_".strtolower($nlmCategory), $text_string)
						);
					}
				}
				$abstract_text = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $abstractText));
				parent::addRDF(
					parent::triplifyString($abstractIdentifier, parent::getVoc()."abstract_text", $abstract_text)
				);
			}

			$otherAbstractNumber = 0;
			foreach($citation->OtherAbstract as $otherAbstract){
				$otherAbstractNumber++;
				if(!empty($otherAbstract)){
					$otherAbstractIdentifier = parent::getRes().$pmid."_OTHER_ABSTRACT_".$otherAbstractNumber;
					$other_abstract_label = "Abstract for ".parent::getNamespace().$pmid;
					parent::addRDF(
						parent::describeIndividual($otherAbstractIdentifier, $other_abstract_label, parent::getVoc()."Article-Abstract").
						parent::describeClass(parent::getVoc()."Article-Abstract","Article Abstract").
						parent::triplify($id, "dc:abstract", $otherAbstractIdentifier)
					);

					$otherAbstractText = "";
					foreach($otherAbstract->AbstractText as $otherText){
						$otherAbstractText .= " ".$otherText;
						if(!empty($otherText['Label']) && $otherText['Label'] !== "UNLABELLED"){
							$otherTextCategory = utf8_encode(str_replace("\"", "", $otherText['Category']));
							$other_text = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $otherText));
							parent::addRDF(
								parent::triplifyString($otherAbstractIdentifier, parent::getVoc()."abstract_".strtolower($otherTextCategory), $other_text)
							);
						}
					}
					$abstract_text = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $otherAbstractText));
					parent::addRDF(
						parent::triplifyString($otherAbstractIdentifier, parent::getVoc()."abstract_text", $abstract_text)
					);
				}
			}

			foreach($citation->Article->Language as $language){
				if(!empty($language)){
					$language =  utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $language));
					parent::addRDF(
						parent::triplifyString($id, "dc:language", $language)
					);
				}
			}

			if(!empty($keywordList)){
				foreach($keywordList->Keyword as $keyword){
					$keyword = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $keyword));
					parent::addRDF(
						parent::triplifyString($id, parent::getVoc()."keyword", $keyword)
					);
				}
			}

			if(!empty($geneSymbolList)){
				foreach($geneSymbolList->GeneSymbol as $geneSymbol){
					$gene_symbol = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $geneSymbol));
					parent::addRDF(
						parent::triplifyString($id, parent::getVoc()."gene_symbol", $gene_symbol)
					);
				}
			}

			if(!empty($dataBankList)){
				foreach($dataBankList->DataBank as $dataBank){
					$accessionNumberList = $dataBank->AccessionNumberList;
					$dataBankName = utf8_encode(str_replace("\"", "", $dataBank->DataBankName));
					parent::addRDF(
						parent::triplifyString($id, parent::getVoc()."databank", $dataBankName)
					);
					if($accessionNumberList !== NULL){
						foreach($accessionNumberList->AccessionNumber as $acc){
							$xref = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $acc));
							parent::addRDF(
								parent::triplifyString($id, parent::getVoc()."x-".strtolower($dataBankName), $xref)
							);
						}
					}
				}
			}

			if(!empty($grantList)){
				$grantNumber = 0;
				foreach($grantList->Grant as $grant){
					$grantNumber++;
					$grantIdentifier = parent::getRes().$pmid."_GRANT_".$grantNumber;
					$grantId = $grant->GrantID;//optional
					$grantAgency = $grant->Agency;
					$grantCountry = $grant->Country;

					$grant_label = "Grant ".$grantNumber."for ".parent::getNamespace().$pmid;

					parent::addRDF(
						parent::describeIndividual($grantIdentifier, $grant_label, parent::getVoc()."Grant").
						parent::describeClass(parent::getVoc()."Grant","Grant").
						parent::triplify($id, parent::getVoc()."grant", $grantIdentifier)
					);

					if(!empty($grantId)){
						$grant_identifier = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $grantId));
						parent::addRDF(
							parent::triplifyString($grantIdentifier, parent::getVoc()."grant_identifier", $grant_identifier)
						);
					}

					if(!empty($grantAcronym)){
						$grant_acronym = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $grantAcronym));
						parent::addRDF(
							parent::triplifyString($grantIdentifier, parent::getVoc()."grant_acronym", $grant_acronym)
						);
					}

					$grant_agency = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $grantAgency));
					$grant_country = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $grantCountry));

					parent::addRDF(
						parent::triplifyString($grantIdentifier, parent::getVoc()."grant_agency", $grant_agency).
						parent::triplifyString($grantIdentifier, parent::getVoc()."grant_country", $grant_country)
					);
				}
			}

			if(!empty($affiliation)){
				$aff = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $affiliation));
				parent::addRDF(
					parent::triplifyString($id, parent::getVoc()."affiliation", $aff)
				);
			}

			if(!empty($numberOfReferences)){
				$number_of_references = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $numberOfReferences));
				parent::addRDF(
					parent::triplifyString($id, parent::getVoc()."number_of_references", $number_of_references)
				);
			}

			if(!empty($vernacularTitle)){
				$vernacular_title = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $vernacularTitle));
				parent::addRDF(
					parent::triplifyString($id, parent::getVoc()."vernacular_title", $vernacular_title)
				);
			}

			if(!empty($copyright)){
				$copyright_information = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $copyright));
				parent::addRDF(
					parent::triplifyString($id, parent::getVoc()."copyright_information", $copyright_information)
				);
			}

			if(!empty($meshHeadingList)){
				$meshHeadingNumber = 0;
				foreach($meshHeadingList->MeshHeading as $meshHeading){
					$meshHeadingNumber++;
					$meshHeadingIdentifier = parent::getRes().$pmid."_MESH_HEADING_".$meshHeadingNumber;
					$descriptorName = $meshHeading->DescriptorName;
					$qualifierName = $meshHeading->QualifierName;
					$mesh_heading_label = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $descriptorName));
					parent::addRDF(
						parent::describeIndividual($meshHeadingIdentifier, $mesh_heading_label, parent::getVoc()."MeshHeading").
						parent::describeClass(parent::getVoc()."MeshHeading","MeSH Heading").
						parent::triplifyString($meshHeadingIdentifier, parent::getVoc()."mesh_descriptor_name", $mesh_heading_label)
					);

					if(!empty($qualifierName)){
						$qualifier_name = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $qualifierName));
						parent::addRDF(
							parent::triplifyString($meshHeadingIdentifier, parent::getVoc()."mesh_qualifier_name", $qualifier_name)
						);
					}

					parent::addRDF(
						parent::triplify($id, parent::getVoc()."mesh_heading", $meshHeadingIdentifier)
					);
				}
			}

			if(!empty($chemicals)){
				$chemicalNumber = 0;
				foreach($chemicals->Chemical as $chemical){
					$chemicalName = $chemical->NameOfSubstance;
					$registryNumber = trim($chemical->RegistryNumber);
					$chemicalNumber++;
					$chemicalIdentifier = parent::getRes().$pmid."_CHEMICAL_".$chemicalNumber;

					$chemical_label = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $chemicalName));

					parent::addRDF(
						parent::describeIndividual($chemicalIdentifier, $chemical_label, parent::getVoc()."Chemical").
						parent::describeClass(parent::getVoc()."Chemical","Chemical")
					);

					if($registryNumber !== "0"){
						parent::addRDF(
							parent::triplifyString($chemicalIdentifier, parent::getVoc()."cas_registry_number", $registryNumber)
						);
					}

					parent::addRDF(
						parent::triplify($id, parent::getVoc()."chemical", $chemicalIdentifier)
					);
				}
			}

			if(!empty($supplMeshList)){
				$supplMeshNumber = 0;
				foreach($supplMeshList->SupplMeshName as $supplMeshName){
					$supplMeshNumber++;
					$supplMeshIdentifier = parent::getRes().$pmid."SUPPL_MESH_HEADING_".$supplMeshNumber;
					$suppl_mesh_label = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $supplMeshName));
					parent::addRDF(
						parent::describeIndividual($supplMeshIdentifier, $suppl_mesh_label, parent::getVoc()."MeshHeading").
						parent::describeClass(parent::getVoc()."MeshHeading","MeshHeading").
						parent::triplifyString($supplMeshIdentifier, parent::getVoc()."mesh_descriptor_name", $suppl_mesh_label).
						parent::triplify($id, parent::getVoc()."suppl_mesh_heading", $supplMeshIdentifier)
					);
				}
			}

			foreach($citation->CitationSubset as $citationSubset){
				if(!empty($citationSubset)){
					$citation_subset = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $citationSubset));
					parent::addRDF(
						parent::triplifyString($id, parent::getVoc()."citation_subset", $citation_subset)
					);
				}
			}

			if(!empty($commentsCorrectionsList)){
				$ccNumber = 0;
				foreach($commentsCorrectionsList->CommentsCorrections as $commentCorrection){
					$ccNumber++;
					$ccRefType = utf8_encode(str_replace("\"", "", $commentCorrection['RefType']));
					$ccPmid = $commentCorrection->PMID;//optional
					$ccNote = $commentCorrection->Note;//optional

					$ccIdentifier = parent::getRes().$pmid."_COMMENT_CORRECTION_".$ccNumber;

					$cc_label = "Comment or correction .".$ccNumber." for ".parent::getNamespace().$pmid;

					parent::addRDF(
						parent::describeIndividual($ccIdentifier, $cc_label, parent::getVoc()."CommentCorrection").
						parent::describeClass(parent::getVoc()."CommentCorrection","CommentCorrection")
					);

					parent::addRDF(
						parent::triplify($ccIdentifier, "rdf:type", parent::getVoc().$ccRefType)
					);

					$ref_source = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $ccRefSource));
					parent::addRDF(
						parent::triplifyString($ccIdentifier, parent::getVoc()."ref_source", $ref_source)
					);

					if(!empty($ccPmid)){
						parent::addRDF(
							parent::triplify($ccIdentifier, parent::getVoc()."pmid", parent::getNamespace().$pmid)
						);
					}

					if(!empty($ccNote)){
						$cc_note = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $ccNote));
						parent::addRDF(
							parent::triplifyString($ccIdentifier, parent::getVoc()."note", $cc_note)
						);
					}	

					parent::addRDF(
						parent::triplify($id, parent::getVoc()."comment_correction", $ccIdentifier)
					);					
				}
			}

			if(!empty($generalNote)){
				$general_note = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $generalNote));
				parent::addRDF(
					parent::triplifyString($id, parent::getVoc()."general_note", $general_note)
				);
			}

			if(!empty($articleDate)){
				$this->formatDate($id,"article_date",$articleDate);
			}

			if(!empty($authorList)){
				$authorNumber = 0;
				foreach($authorList->Author as $author){
					$authorNumber++;
					$authorLastName = $author->LastName;
					$authorForeName = $author->ForeName;//optional
					$authorInitials = $author->Initials;//optional
					$authorCollectiveName = $author->CollectiveName;//optional

					$authorIdentifier = parent::getRes().$pmid."_AUTHOR_".$authorNumber;
					$author_last_name = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $authorLastName));

					$author_label = $author_last_name.", author of ".parent::getNamespace().$pmid;

					parent::addRDF(
						parent::describeIndividual($authorIdentifier, $author_label, parent::getVoc()."Author").
						parent::describeClass(parent::getVoc()."Author","Author")
					);

					parent::addRDF(
						parent::triplifyString($authorIdentifier, parent::getVoc()."last_name", $author_last_name)
					);

					if(!empty($authorForeName)){
						$author_fore_name = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $authorForeName));
						parent::addRDF(
							parent::triplifyString($authorIdentifier, parent::getVoc()."fore_name", $author_fore_name)
						);
					}

					if(!empty($authorInitials)){
						$author_initials = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $authorInitials));
						parent::addRDF(
							parent::triplifyString($authorIdentifier, parent::getVoc()."initials", $author_initials)
						);
					}

					if(!empty($authorCollectiveName)){
						$author_collective_name = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $authorCollectiveName));
						parent::addRDF(
							parent::triplifyString($authorIdentifier, parent::getVoc()."collective_name", $author_collective_name)
						);
					}

					foreach($author->NameID as $authorNameId){
						if(!empty($authorNameId)){
							$author_name_id = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $authorNameId));
							parent::addRDF(
								parent::triplifyString($authorIdentifier, parent::getVoc()."name_id", $author_name_id)
							);
						}
					}
					parent::addRDF(
						parent::triplify($id, parent::getVoc()."author", $authorIdentifier)
					);
				}
			}

			foreach($citation->SpaceFlightMission as $spaceFlightMission){
				if(!empty($spaceFlightMission)){
					$space_flight_mission = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $spaceFlightMission));
					parent::addRDF(
						parent::triplifyString($id, parent::getVoc()."space_flight_mission". $space_flight_mission)
					);
				}
			}

			if(!empty($investigatorList)){
				$investigatorNumber = 0;
				foreach($investigatorList->Investigator as $investigator){
					$investigatorNumber++;
					$iLastName = $investigator->LastName;
					$iForeName = $investigator->ForeName;//optional
					$iInitials = $investigator->Initials;//optional
					$iAffiliation = $investigator->Affiliation;//optional

					$iIdentifier = parent::getRes().$pmid."_INVESTIGATOR_".$investigatorNumber;
					$i_last_name = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $iLastName));
					$i_label = $i_last_name.", investigator for ".parent::getNamespace().$pmid;

					parent::addRDF(
						parent::describeIndividual($iIdentifier, $i_label, parent::getVoc()."Investigator").
						parent::describeClass(parent::getVoc()."Investigator","Investigator")
					);

					parent::addRDF(
						parent::triplifyString($iIdentifier, parent::getVoc()."last_name", $i_last_name)
					);

					if(!empty($iForeName)){
						$i_fore_name = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $iForeName));
						parent::addRDF(
							parent::triplifyString($iIdentifier, parent::getVoc()."fore_name", $i_fore_name)
						);
					}

					if(!empty($iInitials)){
						$i_initials = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $iInitials));
						parent::addRDF(
							parent::triplifyString($iIdentifier, parent::getVoc()."initials", $i_initials)
						);
					}

					if(!empty($iAffiliation)){
						$i_affiliation = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $iAffiliation));
						parent::addRDF(
							parent::triplifyString($iIdentifier, parent::getVoc()."affiliation", $i_affiliation)
						);
					}

					foreach($investigator->NameID as $iNameId){
						if(!empty($iNameId)){
							$i_name_id = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $iNameId));
							parent::addRDF(
								parent::triplifyString($iIdentifier, parent::getVoc()."name_id", $i_name_id)
							);
						}	
					}

					parent::addRDF(
						parent::triplify($id, parent::getVoc()."investigator", $iIdentifier)
					);				
				}
			}

			if(!empty($personalNameSubjectList)){
				$pnsNumber = 0;
				foreach($personalNameSubjectList->PersonalNameSubject as $personalNameSubject){
					$pnsNumber++;
					$pnsIdentifier = parent::getRes().$pmid."_PERSONAL_NAME_SUBJECT_".$pnsNumber;

					$pnsLastName = $personalNameSubject->LastName;
					$pnsForeName = $personalNameSubject->ForeName;//optional
					$pnsInitials = $personalNameSubject->Initials;//optional
					$pnsSuffix = $personalNameSubject->Suffix;//optional

					$pns_last_name = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $pnsLastName));
					$pns_label = $pns_last_name.", personal name subject for ".parent::getNamespace().$pmid;

					parent::addRDF(
						parent::describeIndividual($pnsIdentifier, $pns_label, parent::getVoc()."PersonalNameSubject").
						parent::describeClass(parent::getVoc()."PersonalNameSubject","Personal Name Subject")
					);

					parent::addRDF(
						parent::triplifyString($pnsIdentifier, parent::getVoc()."last_name", $pns_last_name)
					);

					if(!empty($pnsForeName)){
						$pns_fore_name = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $pnsForeName));
						parent::addRDF(
							parent::triplifyString($pnsIdentifier, parent::getVoc()."fore_name", $pns_fore_name)
						);
					}

					if(!empty($pnsInitials)){
						$pns_initials = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $pnsInitials));
						parent::addRDF(
							parent::triplifyString($pnsIdentifier, parent::getVoc()."initials", $pns_initials)
						);
					}

					if(!empty($pnsSuffix)){
						$pns_suffix = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $pnsSuffix));
						parent::addRDF(
							parent::triplifyString($pnsIdentifier, parent::getVoc()."suffix", $pns_suffix)
						);
					}

					parent::addRDF(
						parent::triplify($id, parent::getVoc()."personal_name_subject", $pnsIdentifier)
					);
				}
			}

			$journalISSN = $journal->ISSN;//optional
			$journalIssue = $journal->JournalIssue;
			$journalTitle = $journal->Title;//optional
			$journalAbbrev = $journal->ISOAbbreviation;//optional
			$journalVolume = $journalIssue->Volume;//optional
			$journalIssueIssue = $journalIssue->Issue;//optional
			$journalPubDate = $journalIssue->PubDate;
			$journalNlmID = $citation->MedLineJournalInfo->NlmUniqueID;//optional

			$journalId = parent::getRes().$pmid."_JOURNAL";
			$journal_label = "Journal for ".parent::getNamespace().$pmid;

			parent::addRDF(
				parent::describeIndividual($journalId, $journal_label, parent::getVoc()."Journal").
				parent::describeClass(parent::getVoc()."Journal","Journal")
			);

			if(!empty($journalNlmID)){
				$journal_nlm_identifier = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $journalNlmID));
				parent::addRDF(
					parent::triplifyString($journalId, parent::getVoc()."journal_nlm_identifier", $journal_nlm_identifier)
				);
			}

			if(!empty($journalPubDate)){
				$journalYear = $journalPubDate->Year;
				$journalMonth = trim($journalPubDate->Month);//optional
				if($journalMonth and !is_numeric($journalMonth[0])) {
					$mo = array("jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec");
					$journalMonth = str_pad(array_search(strtolower($journalMonth),$mo)+1, 2, "0",STR_PAD_LEFT);
				}
				$journalDay = trim($journalPubDate->Day);//optional
				if($journalDay) $journalDay = str_pad($journalDay,2,"0",STR_PAD_LEFT);
				parent::addRDF(
					parent::triplifyString($journalId, parent::getVoc()."publication_year", $journalYear).
					parent::triplifyString($journalId, parent::getVoc()."publication_month", $journalMonth).
					parent::triplifyString($journalId, parent::getVoc()."publication_day", $journalDay)
				);

				if(!empty($journalYear) and !empty($journalMonth) and !empty($journalDay)){
					parent::addRDF(
						parent::triplifyString($journalId, parent::getVoc()."publication_date", "$journalYear-$journalMonth-$journalDay", "xsd:date")
					);
				}
				$journalSeason = $journalPubDate->Season;
				if(!empty($journalSeason)){
					$journal_season = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $journalSeason));
					parent::addRDF(
						parent::triplifyString($journalId, parent::getVoc()."publication_season", $journal_season)
					);
				}
				$journalMedlineDate = $journalPubDate->MedlineDate;
				if(!empty($journalMedlineDate)){
					$journal_medline_date = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $journalMedlineDate));
					parent::addRDF(
						parent::triplifyString($journalId, parent::getVoc()."publication_date", $journal_medline_date)
					);
				}
			}

			if(!empty($journalTitle)){
				$journal_title = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $journalTitle));
				parent::addRDF(
					parent::triplifyString($journalId, parent::getVoc()."journal_title", $journal_title)
				);
			}

			if(!empty($journalAbbrev)){
				$journal_abbreviation = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $journalAbbrev));
				parent::addRDF(
					parent::triplifyString($journalId, parent::getVoc()."journal_abbreviation", $journal_abbreviation)
				);
			}

			if(!empty($journalVolume)){
				$journal_volume = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $journalVolume));
				parent::addRDF(
					parent::triplifyString($journalId, parent::getVoc()."journal_volume", $journal_volume)
				);
			}

			if(!empty($journalIssueIssue)){
				$journal_issue = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $journalIssueIssue));
				parent::addRDF(
					parent::triplifyString($journalId, parent::getVoc()."journal_issue", $journal_issue)
				);
			}

			parent::addRDF(
				parent::triplify($id, parent::getVoc()."journal", $journalId)
			);
			
			if(!empty($pagination)){
				$pagination = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $pagination));
				parent::addRDF(
					parent::triplifyString($id, parent::getVoc()."pagination", $pagination)
				);
			}

			foreach($citation->Article->ELocation as $eLocation){
				if(!empty($eLocation)){
					$e_location = utf8_encode(str_replace(array("\\", "\"", "'"), array("/", "", ""), $eLocation));
					parent::addRDF(
						parent::triplifyString($id, parent::getVoc()."elocation", $e_location)
					);
				}
			}
		}
	}
	function formatDate($id,$field,$dateobj) {
		$year = $dateobj->Year;
		$month = $dateobj->Month;
		$day = $dateobj->Day;
		parent::addRDF(
			parent::triplifyString($id, parent::getVoc().$field, "$year-$month-$day", "xsd:date")
		);
	}

}
?>
