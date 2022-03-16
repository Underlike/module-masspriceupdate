<?php

namespace Axilais\MassPriceUpdate\Controller\Adminhtml\Index;

use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Store\Model\StoreManagerInterface;

class Import extends \Magento\Backend\App\Action
{
    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $resultPageFactory = false;

	public function __construct(
		\Magento\Backend\App\Action\Context $context,
		\Magento\Framework\View\Result\PageFactory $resultPageFactory,
		ManagerInterface $managerInterface,
		DirectoryList $directoryList,
		StoreManagerInterface $storeManager
	) {
		$this->resultPageFactory = $resultPageFactory;
		$this->managerInterface = $managerInterface;
		$this->directoryList = $directoryList;
		$this->storeManager = $storeManager;
		parent::__construct($context);
	}

	public function execute()
	{
		$resultPage = $this->resultPageFactory->create();
		$resultPage->getConfig()->getTitle()->prepend((__('Mass Price Update')));

		$request = $this->getRequest();
		if ($request->isPost()) {
			if (!array_key_exists('file', $_FILES) || !$_FILES['file']['name']) {
                $this->managerInterface->addErrorMessage(__('Don\'t have send file'));
                return $resultPage;
			}

			// Create folder with recursive mode
			$pathFolder = $this->directoryList->getPath('var') . '/import/module/masspriceupdate/';
			if (!file_exists($pathFolder)) {
				mkdir($pathFolder, 0777, true);
			}

			// Verify file type
			if($_FILES['file']['type'] != 'text/csv') {
				$this->managerInterface->addErrorMessage(__('Don\'t send file'));
                return $resultPage;
			}

			// Write and copy file
			$filePath = $pathFolder . '/' . time() . '.csv';;
			if (move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
                $this->managerInterface->addSuccessMessage(__('Send file'));
			} else {
                $this->managerInterface->addErrorMessage(__('Don\'t send file'));
			}
		}

		return $resultPage;
	}
}