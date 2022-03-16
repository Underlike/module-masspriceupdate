<?php

namespace Axilais\MassPriceUpdate\Cron;

use Magento\Framework\App\State;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Indexer\Model\IndexerFactory;
use Psr\Log\LoggerInterface;

class SyncPrices 
{
    protected $logger;
    protected $attributes = [
        'price',
        'status'
    ];

    protected $indexers = [
        'catalog_product_price'
    ];

    protected $preloadProducts = [];
    protected $preloadAttributes = [];
    protected $syncedFiles = [];

    public function __construct(
        LoggerInterface $logger,
        ResourceConnection $resourceConnection,
        DirectoryList $directoryList,
        IndexerFactory $indexerFactory
    ) {
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
		$this->connection = $this->resourceConnection->getConnection();
        $this->directoryList = $directoryList;
        $this->indexerFactory = $indexerFactory;

        $uploadDirectory = $this->directoryList->getPath('var') . '/import/module/masspriceupdate/';
        $this->uploadDirectory = $uploadDirectory;
    }

   /**
    * Write to system.log
    *
    * @return void
    */
    public function execute() 
    {
        $this->preloadProducts();
        $this->preloadAttributes();

        /** Create flag import */
        if(file_exists($this->uploadDirectory . 'flag-importing')) {
            return true;
        } else {
            fopen($this->uploadDirectory . 'flag-importing', "a");
        }

        foreach ($this->preloadProducts as $product) {
            /** Search if product send on API have sku */
            if (!$product['sku']) {
                continue;
            }

            /** Search if product send on API have price */
            if (!$product['price']) {
                continue;
            }

            /** Search if product send on API have status */
            if (!array_key_exists('status', $product)) {
                continue;
            }

            /** Search product by sku and continue if not exist */
            $productId = $this->getProductIdBySku($product['sku']);
            if (!$productId) {
                $this->logger->info('Product not found : ' . $product['sku']);
                continue;
            }
            
            /** Execute process for update price and status product */
            if ($this->updateProductPrice($productId, $product['price'])) {
                if($this->updateProductStatus($productId, $product['status'])) {
                    $this->logger->info('Product was updated : ' . $product['sku']);
                }
            }
        }

        /** Delete old flag importing and rename TRT */
        if(file_exists($this->uploadDirectory . 'flag-importing')) {
            unlink($this->uploadDirectory . 'flag-importing');

            foreach ($this->syncedFiles as $file) {
                unlink($file);
            }
        }

        /** Execute indexation */
        $this->reindexEssentialIndexer();
    }

    /**
	 * Preload product from post api
	 */
	protected function preloadProducts()
	{
        $files = [];
        $dirHandle = opendir($this->uploadDirectory);
        while($file = readdir($dirHandle)) {
            if(strpos($file, '.csv') !== false) {
                $files[] = $this->uploadDirectory . $file;
            }
        }

        sort($files);
        foreach($files as $file) {
            $handle = fopen($file, "r");
            while($row = fgetcsv($handle, 0, ";", '"')) {
                $this->preloadProducts[] = [
					'sku' => $row[0],
					'price' => number_format((float) str_replace(',', '.', $row[1]), 4, '.', ''),
					'status' => $row[2]
				];
            }

            $this->syncedFiles[] = $file;
        }
	}

    /**
     * Preload attributes
     */
    protected function preloadAttributes()
    {
        foreach ($this->attributes as $attributeCode) {
            $sql = 'SELECT attribute_id FROM eav_attribute';
            $sql .= ' WHERE entity_type_id = 4 AND attribute_code=:attributeCode';
            $attribute = $this->connection->fetchRow($sql, [
				'attributeCode' => $attributeCode
			]);

            if($attribute){
                $this->preloadAttributes[$attributeCode] = $attribute['attribute_id'];
            }
        }
    }

    /**
     * Updated product price
     */
    protected function updateProductPrice($productId, $price)
    {
        $sql = 'INSERT INTO `catalog_product_entity_decimal` (`entity_id`, `attribute_id`, `value`, `store_id`)';
        $sql .= ' VALUES (:productId, :attributeId, :value, 0), (:productId, :attributeId, :value, 1)';
        $sql .= ' ON DUPLICATE KEY UPDATE `value` = :value';

        if($this->connection->query($sql, array(
            'productId' => $productId,
            'attributeId' => $this->preloadAttributes['price'],
            'value' => $price
        ))) {
            return true;
        }
        return false;
    }

    /**
     * Update product status
     */
    protected function updateProductStatus($productId, $status)
    {
        $sql = 'INSERT INTO `catalog_product_entity_int` (`attribute_id`, `entity_id`, `value`, `store_id`) ';
        $sql .= 'VALUES (:attributeId, :productId, :value, 0), (:attributeId, :productId, :value, 1) ';
        $sql .= 'ON DUPLICATE KEY UPDATE `value` = :value;';

        if($this->connection->query($sql, array(
            'productId' => $productId,
            'attributeId' => $this->preloadAttributes['status'],
            'value' => $status
        ))) {
            return true;
        }
        return false;
    }

    /**
     * Reindex indexers essentials
     */
    protected function reindexEssentialIndexer()
    {
        foreach ($this->indexers as $indexerId) {
            $indexer = $this->indexerFactory->create();
            $indexer->load($indexerId);
            $indexer->reindexAll();
        }
    }

    /**
     * Get Product Id by Sku
     */
    protected function getProductIdBySku($sku)
    {
        $sql = 'SELECT `entity_id` FROM `catalog_product_entity` WHERE `sku` = :sku';
        $productId = $this->connection->fetchOne($sql, [
			'sku' => $sku
		]);
        return ($productId) ? $productId : null;
    }
}