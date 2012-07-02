<?php
/**
 * Report showing publication requests I need to publish
 * 
 * @package cmsworkflow
 * @subpackage ThreeStep
 */
class ApprovedPublications3StepReport extends SS_Report {
	
	/**
	 * @var Array
	 */
	protected $_cache_sourceRecords = array();

	function title() {
		return _t('ApprovedPublications3StepReport.TITLE',"Approved pages I need to publish");
	}
	
	function sourceRecords($params, $sort, $limit) {
		increase_time_limit_to(120);
		
		$cachekey = md5(serialize($params));
		if(!isset($this->_cache_sourceRecords[$cachekey])) {
			$res = WorkflowThreeStepRequest::get_by_publisher(
				'WorkflowPublicationRequest',
				Member::currentUser(),
				array('Approved')
			);

			$doSet = new DataObjectSet();
			foreach ($res as $result) {
				if ($wf = $result->openWorkflowRequest()) {
					$result->WFAuthorID = $wf->AuthorID;
					$result->WFApproverTitle = $wf->Approver()->Title;
					$result->WFAuthorTitle = $wf->Author()->Title;
					$result->WFApprovedWhen = $wf->ApprovalDate();
					$result->WFRequestedWhen = $wf->Created;
					$result->WFApproverID = $wf->ApproverID;
					$result->WFPublisherID = $wf->PublisherID;
					$result->HasExpiry = $wf->ExpiryDate();
					if (isset($_REQUEST['OnlyMine']) && $result->WFApproverID != Member::currentUserID()) continue;
					$doSet->push($result);
				}
			}
			
			$this->_cache_sourceRecords[$cachekey] = $doSet;
		}
		
		$doSet = $this->_cache_sourceRecords[$cachekey];
		if($sort) {
			$parts = explode(' ', $sort);
			$field = $parts[0];
			$direction = $parts[1];
			
			if($field == 'AbsoluteLink') $sort = 'URLSegment ' . $direction;
			if($field == 'Subsite.Title') $sort = 'SubsiteID ' . $direction;
			
			$doSet->sort($sort);
		}

		if($limit && $limit['limit']) return $doSet->getRange($limit['start'], $limit['limit']);
		else return $doSet;
	}
	
	function columns() {
		return array(
			"Title" => array(
				"title" => "Page name",
				'formatting' => '<a href=\"admin/show/$ID\" title=\"Edit page\">$value</a>'
			),
			"WFApproverTitle" => array(
				"title" => "Approver",
			),
			"WFApprovedWhen" => array(
				"title" => "Approved",
				'casting' => 'SS_Datetime->Full'
			),
			"WFAuthorTitle" => array(
				"title" => "Author",
			),
			"WFRequestedWhen" => array(
				"title" => "Requested",
				'casting' => 'SS_Datetime->Full'
			),
			'HasExpiry' => array(
				'title' => 'Expiry',
				'formatting' => '" . ($value ? date("j M Y g:ia", strtotime($value)) : "no") . "'
			),
			'AbsoluteLink' => array(
				'title' => 'URL',
				'formatting' => '$value " . ($AbsoluteLiveLink ? "<a target=\"_blank\" href=\"$AbsoluteLiveLink\">(live)</a>" : "") . " <a target=\"_blank\" href=\"$value?stage=Stage\">(draft)</a>'
			)
		);
	}
	
	/**
	 * This alternative columns method is picked up by SideReportWrapper
	 */
	function sideReportColumns() {
		return array(
			"Title" => array(
				"title" => "Title",
				"link" => true,
			),
			"WFApproverTitle" => array(
				"title" => "Approver",
				"formatting" => 'Approved by $value',
			),
			"WFApprovedWhen" => array(
				"title" => "When",
				"formatting" => ' on $value',
				'casting' => 'SS_Datetime->Full'
			),
		);
	}
	
	function parameterFields() {
		$params = new FieldSet();
		
		$params->push(new CheckboxField(
			"OnlyMine", 
			"Only requests I approved" 
		));
		
		return $params;
	}
	function canView($member = null) {
		return Object::has_extension('SiteTree', 'SiteTreeCMSThreeStepWorkflow');
	}
	
	function group() {
		return _t('WorkflowRequest.WORKFLOW', 'Workflow');
	}
}
?>
