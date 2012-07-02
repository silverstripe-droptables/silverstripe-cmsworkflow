<?php

abstract class SiteTreeCMSWFDecorator extends DataExtension {
	abstract function canDenyRequests();
	abstract function canRequestEdit();
	abstract function whoCanApprove();
	abstract function getOpenRequest($workflowClass);
}
