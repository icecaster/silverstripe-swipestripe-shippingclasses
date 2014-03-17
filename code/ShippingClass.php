<?php

class ShippingClass extends DataObject {

	static $db = array(
		"Name" => "Varchar",
		"Priority" => "Int",
		"IsDefault" => "Boolean",
		"SortOrder" => "Int"
	);

	static $has_many = array(
		"Products" => "Product",
		"Rates" => "FlatFeeShippingRate"
	);

	function onBeforeWrite() {
		parent::onBeforeWrite();
		
		if($this->IsDefault) {
			//unset all objects where IsDefault is set to true
			$defaults = DataList::create(__CLASS__)
				->exclude("ID", $this->ID)
				->filter("IsDefault", true);
			
			foreach($defaults as $default) {
				$default->IsDefault = false;
				$default->write();
			}
		}
	}


	public function requireDefaultRecords() {
		parent::requireDefaultRecords();

		$records = array(
			array(
				"Name" => "Free",
				"Priority" => 0
			),
			array(
				"Name" => "Small",
				"Priority" => 5
			),
			array(
				"Name" => "Medium",
				"Priority" => 10,
				"IsDefault" => 1
			),
			array(
				"Name" => "Large",
				"Priority" => 20
			),
		);

		if(!DataList::create(__CLASS__)->Count()) {
			foreach($records as $record) {
				$obj = new ShippingClass($record);
				$obj->write();
			}
			DB::alteration_message('Default Shipping classes created', 'created');
			
			$rates = FlatFeeShippingRate::get();
			
			if($rates->Count()) {
				$defaultClassID = ShippingClass::get()->filter("IsDefault", 1)->First()->ID;
				foreach($rates as $rate) {
					$rate->ShippingClassID = $defaultClassID;
					$rate->write();
					DB::alteration_message("Existing Shipping rate '{$rate->Title}' linked to default shipping class", 'created');
				}
			}
		}
	}

}

class ShippingClass_ProductExtension extends DataExtension {

	static $has_one = array(
		"ShippingClass" => "ShippingClass"
	);

	function updateCMSFields(FieldList $fields) {
		$fields->addFieldToTab("Root.Main", 
			DropdownField::create("ShippingClassID")
				->setTitle("Shipping Class")
				->setSource(ShippingClass::get()->map())
				->setEmptyString("- Default -")
		);
	}

	function getShippingClass() {
		return ShippingClass::get()->byID($this->getShippingClassOrDefaultID());
	}

	function getShippingClassOrDefaultID() {
		if($this->owner->ShippingClassID > 0) {
			return $this->owner->ShippingClassID;
		} else {
			return ShippingClass::get()->filter("IsDefault", 1)->First()->ID;
		}
	}
	
}


class ShippingClass_FlatFeeShippingRateExtension extends DataExtension {
	
	static $has_one = array(
		"ShippingClass" => "ShippingClass"
	);

}


class ShippingClass_FlatFeeShippingModificationExtension extends DataExtension {

	function updateFlatShippingRates(&$rates, $country) {
		$modification = $this->owner;
		$order = $modification->Order();
		// Order::Products() returns ArrayList, need DataList to allow map() with Callback
		$products = Product::get()->byIDs($order->Products()->map("ID","ID"));


		$shippingClassIDs = $products->map("ShippingClassID", "getShippingClassOrDefaultID")->toArray();

		$highest_class = ShippingClass::get()
			->byIDs($shippingClassIDs)
			->Sort("Priority DESC")
			->First();

		$rates->where("ShippingClassID = '{$highest_class->ID}'");
	}
}


class ShippingClass_Admin extends ShopAdmin {

	static $url_rule = 'ShopConfig/ShippingClasses';
	static $url_priority = 110;
	static $menu_title = 'Shop Shipping Classes';

	public static $url_handlers = array(
		'ShopConfig/ShippingClasses/ShippingClassesForm' => 'ShippingClassesForm',
		'ShopConfig/ShippingClasses' => 'ShippingClassesSettings'
	);

	static $allowed_actions = array(
		"ShippingClassesSettings",
		"ShippingClassesForm"
	);

	public function init() {
		parent::init();
		$this->modelClass = 'ShopConfig';
	}

	public function Breadcrumbs($unlinked = false) {

		$request = $this->getRequest();
		$items = parent::Breadcrumbs($unlinked);

		if ($items->count() > 1) $items->remove($items->pop());

		$items->push(new ArrayData(array(
			'Title' => 'Shipping Classes',
			'Link' => $this->Link(Controller::join_links($this->sanitiseClassName($this->modelClass), 'ShippingClasses'))
		)));

		return $items;
	}

	public function SettingsForm($request = null) {
		return $this->ShippingClassesForm();
	}

	public function ShippingClassesSettings($request) {

		if ($request->isAjax()) {
			$controller = $this;
			$responseNegotiator = new PjaxResponseNegotiator(
				array(
					'CurrentForm' => function() use(&$controller) {
						return $controller->ShippingClassesForm()->forTemplate();
					},
					'Content' => function() use(&$controller) {
						return $controller->renderWith('ShopAdminSettings_Content');
					},
					'Breadcrumbs' => function() use (&$controller) {
						return $controller->renderWith('CMSBreadcrumbs');
					},
					'default' => function() use(&$controller) {
						return $controller->renderWith($controller->getViewer('show'));
					}
				),
				$this->response
			);
			return $responseNegotiator->respond($this->getRequest());
		}
		
		return $this->renderWith('ShopAdminSettings');
	}

	public function ShippingClassesForm() {
		$fields = new FieldList(
			$rootTab = new TabSet('Root',
				$tabMain = new Tab('ShippingClasses',
					GridField::create(
						'ShippingClasses',
						'Shipping Classes',
						ShippingClass::get(),
						GridFieldConfig_HasManyRelationEditor::create()
					)
				)
			)
		);

		$actions = new FieldList();

		$form = new Form(
			$this,
			'ShippingClassesForm',
			$fields,
			$actions
		);

		$form->setTemplate('ShopAdminSettings_EditForm');
		$form->setAttribute('data-pjax-fragment', 'CurrentForm');
		$form->addExtraClass('cms-content cms-edit-form center ss-tabset');
		if($form->Fields()->hasTabset()) $form->Fields()->findOrMakeTab('Root')->setTemplate('CMSTabSet');
		$form->setFormAction(Controller::join_links($this->Link($this->sanitiseClassName($this->modelClass)), 'ShippingClasses/ShippingClassesForm'));

		return $form;
	}

	public function getSnippet() {

		if (!$member = Member::currentUser()) return false;
		if (!Permission::check('CMS_ACCESS_' . get_class($this), 'any', $member)) return false;

		return $this->customise(array(
			'Title' => 'Shipping Class Management',
			'Help' => 'Create and manage Shipping Classes',
			'Link' => Controller::join_links($this->Link('ShopConfig'), 'ShippingClasses'),
			'LinkTitle' => 'Edit shipping classes'
		))->renderWith('ShopAdmin_Snippet');
	}

}