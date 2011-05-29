<?php
/**
 * These tests test the batch actions that are available on
 * two and three step. Though we are testing it using
 * two step.
 *
 * @package cmsworkflow
 * @subpackage tests
 */
class WorkflowBatchActionTest extends FunctionalTest {
	
	static $fixture_file = 'cmsworkflow/tests/SiteTreeCMSWorkflowTest.yml';
	
	protected $requiredExtensions = array(
		'SiteTree' => array('SiteTreeCMSTwoStepWorkflow'),
		'SiteConfig' => array('SiteConfigTwoStepWorkflow'),
		'WorkflowRequest' => array('WorkflowTwoStepRequest'),
	);

	protected $illegalExtensions = array(
		'SiteTree' => array('SiteTreeCMSThreeStepWorkflow'),
		'WorkflowRequest' => array('WorkflowThreeStepRequest'),
		'LeftAndMain' => array('LeftAndMainCMSThreeStepWorkflow'),
		'SiteConfig' => array('SiteConfigThreeStepWorkflow'),
	);
	
	function testBatchSetResetEmbargo() {
		$oldRequest = $_REQUEST;
		
		$action = new BatchSetEmbargo();
		$this->assertTrue(is_string($action->getActionTitle()));
		$this->assertTrue(is_string($action->getDoingText()));
		$this->assertTrue($action->getParameterFields() instanceof FieldSet);
		
		$this->logInAs($this->objFromFixture('Member', 'admin'));
		
		$page1 = new Page();
		$page1->write();
		$page1->openOrNewWorkflowRequest('WorkflowPublicationRequest');
		$page1ID = $page1->ID;
		
		$page2 = new Page();
		$page2->write();
		$page2ID = $page2->ID;
		
		$pages = new DataObjectSet();
		$pages->push($page1);
		$pages->push($page2);
		
		$this->assertEquals(array($page1->ID), $action->applicablePages($pages->column('ID')),
			'applicableIds only returns pages with open requests');
		
		SS_Datetime::set_mock_now('2009-06-15 15:00:00');

		if(class_exists('PopupDateTimeField')) {
			$_REQUEST['EmbargoDate_Batch'] = array(
				'Date' => '01/01/2010',
				'Time' => '3:00 pm'
			);
		}	else {
			$_REQUEST['EmbargoDate_Batch'] = array(
				'date' => '01/01/2010',
				'time' => '3:00 pm'
			);
		}
		
		$_REQUEST['ajax'] = 1;
		$action->run($pages);
		
		$page1 = DataObject::get_by_id('Page', $page1ID);
		$page2 = DataObject::get_by_id('Page', $page2ID);
		
		$this->assertEquals($page1->openWorkflowRequest()->EmbargoDate, '2010-01-01 15:00:00');
		$this->assertNull($page2->openWorkflowRequest());
		
		// Now test resetting
		$action = new BatchResetEmbargo();
		$this->assertTrue(is_string($action->getActionTitle()));
		$this->assertTrue(is_string($action->getDoingText()));
		
		$pages = new DataObjectSet();
		$pages->push($page1);
		$pages->push($page2);
		
		$this->assertEquals(array($page1->ID), $action->applicablePages($pages->column('ID')),
			'applicableIds only returns pages with open requests');
	
		$action->run($pages);
		
		$page1 = DataObject::get_by_id('Page', $page1ID);
		$page2 = DataObject::get_by_id('Page', $page2ID);
		
		$this->assertNull($page1->openWorkflowRequest()->EmbargoDate);
		$this->assertNull($page2->openWorkflowRequest());
		
		$_REQUEST = $oldRequest;
		SS_Datetime::clear_mock_now();
	}
	
	function testBatchSetResetExpiry() {
		$oldRequest = $_REQUEST;
		
		$action = new BatchSetExpiry();
		$this->assertTrue(is_string($action->getActionTitle()));
		$this->assertTrue(is_string($action->getDoingText()));
		$this->assertTrue($action->getParameterFields() instanceof FieldSet);
		
		$this->logInAs($this->objFromFixture('Member', 'admin'));
		
		$page1 = new Page();
		$page1->write();
		$page1->openOrNewWorkflowRequest('WorkflowPublicationRequest');
		$page1ID = $page1->ID;
		
		$page2 = new Page();
		$page2->Content = '<a href="'.$page1->AbsoluteLink().'">link here</a>';
		$page2->write();
		$page2ID = $page2->ID;

		$pages = new DataObjectSet();
		$pages->push($page1);
		$pages->push($page2);
		
		$this->assertEquals(array($page1->ID), $action->applicablePages($pages->column('ID')),
			'applicableIds only returns pages with open requests');
		
		SS_Datetime::set_mock_now('2009-06-15 15:00:00');
			
		if(class_exists('PopupDateTimeField')) {
			$_REQUEST['ExpiryDate_Batch'] = array(
				'Date' => '01/01/2010',
				'Time' => '3:00 pm'
			);
		}	else {
			$_REQUEST['ExpiryDate_Batch'] = array(
				'date' => '01/01/2010',
				'time' => '3:00 pm'
			);
		}
		$_REQUEST['ajax'] = 1;
		
		// Test confirmation dialog
		$page1->BacklinkTracking()->add($page2);
		$confirmation = $action->confirmationDialog($pages->column('ID'));
		$this->assertTrue($confirmation['alert']);
		
		$action->run($pages);
		
		$page1 = DataObject::get_by_id('Page', $page1ID);
		$page2 = DataObject::get_by_id('Page', $page2ID);
		
		$this->assertEquals($page1->ExpiryDate, '2010-01-01 15:00:00');
		$this->assertNull($page2->openWorkflowRequest());
		$this->assertNull($page2->ExpiryDate);
		
		// Now test resetting
		$action = new BatchResetExpiry();
		$this->assertTrue(is_string($action->getActionTitle()));
		$this->assertTrue(is_string($action->getDoingText()));
		
		$pages = new DataObjectSet();
		$pages->push($page1);
		$pages->push($page2);
		
		$this->assertEquals(array($page1->ID), $action->applicablePages(array($page1->ID, $page2->ID)),
			'applicableIds only returns pages with open requests');

		$action->run($pages);
		
		$page1 = DataObject::get_by_id('Page', $page1ID);
		$page2 = DataObject::get_by_id('Page', $page2ID);
		
		$this->assertNull($page1->openWorkflowRequest()->ExpiryDate);
		$this->assertNull($page2->openWorkflowRequest());
		
		$_REQUEST = $oldRequest;
		SS_Datetime::clear_mock_now();
	}
	
}
?>
