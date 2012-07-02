<?php

/**
 * ThreeStep, where an item is actioned immediately.
 *
 * @package cmsworkflow
 * @subpackage twostep
 * @author Tom Rix
 */
class WorkflowTwoStepRequest extends WorkflowRequestDecorator {
	
	static $default_alerts = array(
		'WorkflowPublicationRequest' => array(
			'request' => array(
				'author' => false,
				'publisher' => true
			),
			'approve' => array(
				'author' => true,
				'publisher' => true
			),
			'deny' => array(
				'author' => true,
				'publisher' => true
			),
			'cancel' => array(
				'author' => true,
				'publisher' => true
			),
			'comment' => array(
				'author' => false,
				'publisher' => false
			),
			'requestedit' => array(
				'author' => true,
				'publisher' => false,
				'approver' => false
			)
		),
		'WorkflowDeletionRequest' => array(
			'request' => array(
				'author' => false,
				'publisher' => true
			),
			'approve' => array(
				'author' => true,
				'publisher' => true
			),
			'deny' => array(
				'author' => true,
				'publisher' => true
			),
			'cancel' => array(
				'author' => true,
				'publisher' => true
			),
			'comment' => array(
				'author' => false,
				'publisher' => false
			)
		)
	);
	
	function approve($comment, $member = null, $notify = true) {
		if(!$member) $member = Member::currentUser();
		if(!$this->owner->Page()->canPublish($member)) return false;
		
		if ($this->owner->ClassName == 'WorkflowDeletionRequest') {
			if (isset($_REQUEST['DeletionScheduling']) && $_REQUEST['DeletionScheduling'] == 'scheduled') {
				// Update SiteTree_Live directly, rather than doing a publish
				// Because otherwise, unauthorized edits could be pushed live.
				
				list($day, $month, $year) = explode('/', $_REQUEST['ExpiryDate']['Date']);
				$expiryTimestamp = Convert::raw2sql(date('Y-m-d H:i:s', strtotime("$year-$month-$day {$_REQUEST['ExpiryDate']['Time']}")));
				$pageID = $this->owner->Page()->ID;
			
				if ($expiryTimestamp)
				
				DB::query("UPDATE \"SiteTree_Live\" SET \"ExpiryDate\" = '$expiryTimestamp' WHERE \"ID\" = $pageID");
			}
		}

		$this->owner->PublisherID = $member->ID;
		$this->owner->Status = 'Approved';
		$this->owner->write();
		
		// Embargo means we go Approved -> Scheduled
		if($this->owner->EmbargoDate) {
			$this->owner->setSchedule();
			$this->owner->addNewChange($comment, $this->owner->Status, $member);

		// Otherwise we go Approved -> Published
		} else {
			$this->owner->publish($comment, $member, $notify);
		}
		
		return _t('SiteTreeCMSWorkflow.APPROVEDANDPUBLISHMESSAGE','Approved request and published changes to live version. Emailed %s.');
	}
	
	function saveAndPublish($comment, $member = null, $notify = true) {
		return $this->approve($comment, $member, $notify);
	}

	function notifyPublished($comment) {
		$this->notifyApproved($comment);
	}
	
	function notifyApproved($comment) {
		$emailsToSend = array();
		$userWhoApproved = Member::currentUser();
		
		if (WorkflowRequest::should_send_alert(get_class($this->owner), 'approve', 'publisher')) {
			$publishers = $this->owner->Page()->PublisherMembers();
			foreach($publishers as $publisher) $emailsToSend[] = array($userWhoApproved, $publisher);
		}
		if (WorkflowRequest::should_send_alert(get_class($this->owner), 'approve', 'author')) {
			$emailsToSend[] = array($userWhoApproved, $this->owner->Author());
		}
		
		if (count($emailsToSend)) {
			foreach($emailsToSend as $email) {
				if ($email[1]->ID == Member::currentUserID()) continue;
				$this->owner->sendNotificationEmail(
					$email[0], // sender
					$email[1], // recipient
					$comment,
					'approved changes'
				);
			}
		}
	}
	
	function notifyComment($comment) {
		$commentor = Member::currentUser();
		$emailsToSend = array();
		
		if (WorkflowRequest::should_send_alert(get_class($this->owner), 'comment', 'publisher')) {
			$publishers = $this->owner->Page()->PublisherMembers();
			foreach($publishers as $publisher) $emailsToSend[] = array($commentor, $publisher);
		}
		if (WorkflowRequest::should_send_alert(get_class($this->owner), 'comment', 'author')) {
			$emailsToSend[] = array($commentor, $this->owner->Author());
		}
		
		if (count($emailsToSend)) {
			foreach($emailsToSend as $email) {
				if ($email[1]->ID == Member::currentUserID()) continue;
				$this->owner->sendNotificationEmail(
					$email[0], // sender
					$email[1], // recipient
					$comment,
					'commented'
				);
			}
		}
	}
	
	/**
	 * Notify any publishers assigned to this page when a new request
	 * is lodged.
	 */
	public function notifyAwaitingApproval($comment) {
		$author = $this->owner->Author();
		$emailsToSend = array();
		
		if (WorkflowRequest::should_send_alert(get_class($this->owner), 'request', 'publisher')) {
			$publishers = $this->owner->Page()->PublisherMembers();
			foreach($publishers as $publisher) $emailsToSend[] = array($author, $publisher);
		}
		if (WorkflowRequest::should_send_alert(get_class($this->owner), 'request', 'author')) {
			$emailsToSend[] = array($author, $author);
		}
		
		if (count($emailsToSend)) {
			foreach($emailsToSend as $email) {
				$this->owner->sendNotificationEmail(
					$email[0], // sender
					$email[1], // recipient
					$comment,
					'requested approval'
				);
			}
		}
	}
	
	/**
	 * Return the actions that can be performed on this workflow request.
	 * @return array The key is a LeftAndMainCMSWorkflow action, and the value is a label
	 * for the buton.
	 * @todo There's not a good separation between model and control in this stuff.
	 */
	function WorkflowActions() {
		$actions = array();
		
		if($this->owner->Status == 'AwaitingApproval' && $this->owner->Page()->canPublish()) {
			$actions['cms_approve'] = _t("SiteTreeCMSWorkflow.WORKFLOWACTION_APPROVE", "Approve");
			if (get_class($this->owner) != 'WorkflowDeletionRequest') $actions['cms_requestedit'] = _t("SiteTreeCMSWorkflow.WORKFLOWACTION_REQUESTEDIT", "Request edit");
			if (WorkflowRequest::$allow_deny) $actions['cms_deny'] = _t("SiteTreeCMSWorkflow.WORKFLOW_ACTION_DENY","Deny");
		} else if($this->owner->Status == 'AwaitingEdit' && $this->owner->Page()->canEdit()) {
			// @todo this couples this class to its subclasses. :-(
			$requestAction = (get_class($this->owner) == 'WorkflowDeletionRequest') ? 'cms_requestdeletefromlive' : 'cms_requestpublication';
			$actions[$requestAction] = _t("SiteTreeCMSWorkflow.WORKFLOW_ACTION_RESUBMIT", "Re-submit");
		}
		
		if ($this->owner->Page()->canEdit()) {
			$actions['cms_cancel'] = _t("SiteTreeCMSWorkflow.WORKFLOW_ACTION_CANCEL","Cancel");
		}
		$actions['cms_comment'] = _t("SiteTreeCMSWorkflow.WORKFLOW_ACTION_COMMENT", "Comment");
		
		return $actions;
	}
	
	/**
	 * Get all publication requests assigned to a specific publisher
	 * 
	 * @param string $class WorkflowRequest subclass
	 * @param Member $publisher
	 * @param array $status One or more stati from the $Status property
	 * @return DataObjectSet
	 */
	public static function get_by_publisher($class, $publisher, $status = null) {
		// To ensure 2.3 and 2.4 compatibility
		$bt = defined('DB::USE_ANSI_SQL') ? "\"" : "`";

		if($status) $statusStr = "'".implode("','", $status)."'";

		$classes = (array)ClassInfo::subclassesFor($class);
		$classes[] = $class;
		$classesSQL = implode("','", $classes);
		
		// build filter
		$filter = "{$bt}WorkflowRequest{$bt}.{$bt}ClassName{$bt} IN ('$classesSQL')
		";
		if($status) {
			$filter .= "AND {$bt}WorkflowRequest{$bt}.{$bt}Status{$bt} IN (" . $statusStr . ")";
		} 

		$return = DataObject::get(
			"SiteTree", 
			$filter, 
			"{$bt}SiteTree{$bt}.{$bt}LastEdited{$bt} DESC",
			"LEFT JOIN {$bt}WorkflowRequest{$bt} ON {$bt}WorkflowRequest{$bt}.{$bt}PageID{$bt} = {$bt}SiteTree{$bt}.{$bt}ID{$bt} " .
			"LEFT JOIN {$bt}WorkflowRequest_Approvers{$bt} ON {$bt}WorkflowRequest{$bt}.{$bt}ID{$bt} = {$bt}WorkflowRequest_Approvers{$bt}.{$bt}WorkflowRequestID{$bt}"
		);
		if (!$return) {
			return new DataObjectSet();
		}
		$canPublish = SiteTree::batch_permission_check($return->column('ID'), $publisher->ID, 'CanPublishType', 'SiteTree_PublisherGroups', 'canPublish');		
		foreach($return as $page) {
			if (!isset($canPublish[$page->ID]) || !$canPublish[$page->ID]) {
				$return->remove($page);
			}
		}
		
		return $return;
	}
	
	public static function get_by_author($class, $author, $status = null) {
		return WorkflowRequest::get_by_author($class, $author, $status);
	}
	
	public static function get_by_status($class, $status = null) {
		return WorkflowRequest::get_by_status($class, $status);
	}
}
