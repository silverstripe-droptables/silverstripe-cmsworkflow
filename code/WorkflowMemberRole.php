<?php
/**
 * @package cmsworkflow
 */
class WorkflowMemberRole extends DataExtension {
	
	function extraStatics($class = null, $extension = null) {
		return array(
			'has_many' => array(
				'AuthoredPublicationRequests' => 'WorkflowPublicationRequest',
				'AuthoredDeletionRequests' => 'WorkflowDeletionRequest',
			),
			'many_many' => array(
				'PublicationRequests' => 'WorkflowPublicationRequest',
				'DeletionRequests' => 'WorkflowDeletionRequest',
			)
		);
	}
	
	function updateCMSFields(FieldList $fields) {
		$fields->removeByName('AuthoredPublicationRequests');
		$fields->removeByName('AuthoredDeletionRequests');
		$fields->removeByName('PublicationRequests');
		$fields->removeByName('DeletionRequests');
	}
}
