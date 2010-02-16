<?php
/**
 * Report showing publication requests I need to approve
 * 
 * @package cmsworkflow
 * @subpackage ThreeStep
 */
class ThreeStepWorkflowPublicationRequestsNeedingApprovalReport extends SSReport {
	function title() {
		return _t('ThreeStepWorkflowPublicationRequestsNeedingApprovalReport.TITLE',"Workflow: publication requests I need to approve");
	}
	
	function sourceRecords($params, $sort, $limit) {
		if(!empty($params['Subsites'])) {
			// 'any' wasn't selected
			$subsiteIds = array();
			foreach(explode(',', $params['Subsites']) as $subsite) {
				if(is_numeric(trim($subsite))) $subsiteIds[] = trim($subsite);
			}
			Subsite::$force_subsite = join(',', $subsiteIds);
		}
		
		$res = WorkflowThreeStepRequest::get_by_approver(
			'WorkflowPublicationRequest',
			Member::currentUser(),
			array('AwaitingApproval')
		);
		
		$doSet = new DataObjectSet();
		if ($res) {
			foreach ($res as $result) {
				if ($wf = $result->openWorkflowRequest()) {
					if (!$result->canApprove()) continue;
					if(ClassInfo::exists('Subsite')) $result->SubsiteTitle = $result->Subsite()->Title;
					$result->AuthorTitle = $wf->Author()->Title;
					$result->RequestedAt = $wf->Created;
					$result->HasEmbargoOrExpiry = $wf->getEmbargoDate() || $wf->ExpiryDate() ? 'yes' : 'no';
					$doSet->push($result);
				}
			}
		}
		
		if ($sort) $doSet->sort($sort);
		
		// Manually manage the subsite filtering
		if(ClassInfo::exists('Subsite')) Subsite::$force_subsite = null;
		
		return $doSet;
	}
	
	function columns() {
		$fields = array(
			'Title' => 'Title',
			'AuthorTitle' => 'Requested by',
			'RequestedAt' => array(
				'title' => 'Requested at',
				'casting' => 'SSDatetime->Full'
			),
			'HasEmbargoOrExpiry' => 'Embargo or expiry dates set',
			'ID' => array(
				'title' => 'Actions',
				'formatting' => '<a href=\"admin/show/$value\">Edit in CMS</a>'
			),
			'AbsoluteLink' => array(
				'title' => 'Links',
				'formatting' => '$value <a href=\"$value?stage=Live\">(live)</a> <a href=\"$value?stage=Stage\">(draft)</a>'
			)
		);
		
		if(class_exists('Subsite')) {
			$fields['SubsiteTitle'] = 'Subsite';
		}
		
		return $fields;
	}
	
	function sortColumns() {
		return array(
			'SubsiteTitle',
			'AuthorTitle',
			'RequestedAt'
		);
	}
	
	function parameterFields() {
		$params = new FieldSet();
		
		if (class_exists('Subsite') && $subsites = DataObject::get('Subsite')) {
			$options = $subsites->toDropdownMap('ID', 'Title', 'All sites');
			$params->push(new TreeMultiselectField('Subsites', 'Sites', $options));
		}
		
		return $params;
	}
	function canView() {
		return Object::has_extension('SiteTree', 'SiteTreeCMSThreeStepWorkflow');
	}
}