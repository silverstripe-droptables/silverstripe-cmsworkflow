<?php
/**
 * Report showing removal requests I need to approve
 * 
 * @package cmsworkflow
 * @subpackage ThreeStep
 */
class UnapprovedDeletions3StepReport extends SSReport {
	function title() {
		return _t('UnapprovedDeletions3StepReport.TITLE',"Deletion requests I need to approve");
	}
	function sourceRecords($params) {
		$res = WorkflowThreeStepRequest::get_by_approver(
			'WorkflowDeletionRequest',
			Member::currentUser(),
			array('AwaitingApproval')
		);
		$doSet = new DataObjectSet();
		if ($res) {
			foreach ($res as $result) {
				if ($wf = $result->openWorkflowRequest()) {
					if (!$result->canApprove()) continue;
					$result->WFAuthorTitle = $wf->Author()->Title;
					$result->WFAuthorID = $wf->AuthorID;
					$result->WFRequestedWhen = $wf->Created;
					$result->WFApproverID = $wf->ApproverID;
					$result->WFPublisherID = $wf->PublisherID;
					$doSet->push($result);
				}
			}
		}
		
		return $doSet;
	}
	function columns() {
		return array(
			"Title" => array(
				"title" => "Title",
				'formatting' => '<a href=\"admin/show/$ID\" title=\"Edit page\">$value</a>'
			),
			"WFAuthorTitle" => array(
				"title" => "Requested by",
			),
			"WFRequestedWhen" => array(
				"title" => "Requested",
				'casting' => 'SSDatetime->Full',
			),
			'AbsoluteLink' => array(
				'title' => 'URL',
				'formatting' => '$value " . ($AbsoluteLiveLink ? "<a href=\"$AbsoluteLiveLink\">(live)</a>" : "") . " <a href=\"$value?stage=Stage\">(draft)</a>'
			)
		);
	}

	
	/**
	 * This alternative columns method is picked up by SideReportWrapper
	 */
	function sideReportColumns() {
		return array(
			"Title" => array(
				"link" => true,
			),
			"WFRequesterEmail" => array(
				"formatting" => 'Requested by $value',
			),
			"WFRequestedWhen" => array(
				"formatting" => ' on $value',
				'casting' => 'SSDatetime->Full'
			),
		);
	}
	function canView() {
		return Object::has_extension('SiteTree', 'SiteTreeCMSThreeStepWorkflow');
	}
}