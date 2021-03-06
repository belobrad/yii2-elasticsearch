<?php

namespace mikemadisonweb\elasticsearch;

use Elasticsearch\ClientBuilder;
use Elasticsearch\ConnectionPool\Selectors\StickyRoundRobinSelector;
use Elasticsearch\ConnectionPool\StaticNoPingConnectionPool;
use Elasticsearch\Serializers\SmartSerializer;
use mikemadisonweb\elasticsearch\components\Finder;
use mikemadisonweb\elasticsearch\components\Indexer;
use mikemadisonweb\elasticsearch\components\Percolator;
use yii\base\Component;
use yii\base\InvalidConfigException;

class Configuration extends Component
{
    public $clients = [];
    public $indexes = [];

    protected $connections = [];
    protected $selectedClient;

    public function init()
    {
        parent::init();

        if (empty($this->clients)) {
            throw new InvalidConfigException('No clients configured for `elasticsearch` component.');
        }
        if (empty($this->indexes)) {
            throw new InvalidConfigException('No indexes configured for `elasticsearch` component.');
        }
    }

    /**
     * @param string $clientName
     * @return mixed
     * @throws InvalidConfigException
     */
    public function selectClient($clientName = '')
    {
        if (!$clientName) {
            // First one is client by default
            $clientConfig = reset($this->clients);
            $clientName = key($this->clients);
        } else {
            if (!isset($this->clients[$clientName])) {
                throw new InvalidConfigException("`{$clientName}` client is not configured.");
            }
            $clientConfig = $this->clients[$clientName];
        }

        // Reuse existing connection
        if (isset($this->connections[$clientName])) {
            return $this->connections[$clientName];
        }
        $clientConfig = $this->applyDefaults($clientConfig, $this->getDefaultClientOptions());
        $this->selectedClient = $clientName;
        $client = ClientBuilder::fromConfig($clientConfig);
        $this->connections[$clientName] = $client;

        return $client;
    }

    /**
     * @param $indexName
     * @param string $mapping
     * @param string $clientName
     * @return object
     */
    public function getFinder($indexName = '', $mapping = '', $clientName = '')
    {
        if ('' !== $indexName) {
            $index = current(array_filter($this->indexes, function ($index) use ($indexName) {
                return $index['index'] === $indexName;
            }));
            $mapping = $this->validateMapping($index, $mapping);
        } else {
            $index = [];
        }
        $client = $this->selectClient($clientName);
        $finder = \Yii::createObject(Finder::class, [$index, $mapping, $client]);

        return $finder;
    }

    /**
     * @param $indexName
     * @param string $mapping
     * @param string $clientName
     * @return object
     */
    public function getPercolator($indexName, $mapping = '', $clientName = '')
    {
        $index = current(array_filter($this->indexes, function ($index) use ($indexName) {
            return $index['index'] === $indexName;
        }));
        $mapping = $this->validateMapping($index, $mapping);
        $client = $this->selectClient($clientName);
        if (isset($this->clients[$this->selectedClient]['serializer'])) {
            $serializerClassName = $this->clients[$this->selectedClient]['serializer'];
        } else {
            $serializerClassName = SmartSerializer::class;
        }
        $percolator = \Yii::createObject(Percolator::class, [$index, $mapping, $client]);
        $serializer = \Yii::createObject($serializerClassName);
        $percolator->setSerializer($serializer);

        return $percolator;
    }

    /**
     * @param $indexName
     * @param string $mapping
     * @param string $clientName
     * @return object
     */
    public function getIndexer($indexName, $mapping = '', $clientName = '')
    {
        $index = current(array_filter($this->indexes, function ($index) use ($indexName) {
            return $index['index'] === $indexName;
        }));
        $mapping = $this->validateMapping($index, $mapping);
        $client = $this->selectClient($clientName);

        return \Yii::createObject(Indexer::class, [$index, $mapping, $client]);
    }

    /**
     * @param $indexName
     * @param $json
     * @param string $mappingName
     * @param string $clientName
     * @return array
     */
    public function sendJson($json, $indexName, $mappingName = '', $clientName = '')
    {
        $finder = $this->getFinder($indexName, $mappingName, $clientName);

        return $finder->sendJson($json);
    }

    public function createAllIndexes()
    {
        foreach ($this->indexes as $index) {
            $this->createIndex($index);
        }
    }

    /**
     * @param $indexName
     * @throws InvalidConfigException
     */
    public function createIndexByName($indexName)
    {
        $indexMatch = array_filter($this->indexes, function ($index) use ($indexName) {
            return isset($index['index']) && $indexName === $index['index'];
        });

        if (empty($indexMatch)) {
            throw new InvalidConfigException("`{$indexName}` index is not configured.");
        }
        $this->createIndex(current($indexMatch));
    }

    public function dropAllIndexes()
    {
        foreach ($this->indexes as $index) {
            $this->dropIndex($index);
        }
    }

    /**
     * @param $indexName
     * @throws InvalidConfigException
     */
    public function dropIndexByName($indexName)
    {
        $indexMatch = array_filter($this->indexes, function ($index) use ($indexName) {
            return isset($index['index']) && $indexName === $index['index'];
        });

        if (empty($indexMatch)) {
            throw new InvalidConfigException("`{$indexName}` index is not configured.");
        }
        $this->dropIndex(current($indexMatch));
    }

    /**
     * @param $index
     */
    protected function createIndex($index)
    {
        if (!isset($index['client']['name'])) {
            $index['client']['name'] = '';
        }
        $client = $this->selectClient($index['client']['name']);
        $indexExist = $client->indices()->exists(['index' => $index['index']]);
        if ($indexExist) {
            $client->indices()->delete(['index' => $index['index']]);
        }

        $client->indices()->create([
            'index' => $index['index'],
            'client' => $index['client'],
            'body' => $index['body'],
        ]);
    }

    /**
     * @param $index
     */
    protected function dropIndex($index)
    {
        if (!isset($index['client']['name'])) {
            $index['client']['name'] = '';
        }
        $client = $this->selectClient($index['client']['name']);
        $client->indices()->delete(['index' => $index['index']]);
    }

    /**
     * @return array
     */
    private function getDefaultClientOptions()
    {
        return [
            'hosts' => [],
            'retries' => 2,
            'connectionPool' => StaticNoPingConnectionPool::class,
            'selector' => StickyRoundRobinSelector::class,
            'serializer' => SmartSerializer::class,
            'handler' => ClientBuilder::defaultHandler(),
        ];
    }

    /**
     * @param array $config
     * @param array $defaults
     * @return array
     */
    private function applyDefaults(array $config, array $defaults)
    {
        foreach ($defaults as $key => $value) {
            if (!isset($config[$key])) {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    /**
     * @param array $index
     * @param $mapping
     * @return mixed
     * @throws InvalidConfigException
     */
    private function validateMapping(array $index, $mapping)
    {
        if (!isset($index['body']['mappings']) || empty($index['body']['mappings'])) {
            throw new InvalidConfigException("At least one mapping should be configured for {$index['index']}.");
        }
        $mappings = $index['body']['mappings'];
        if ($mapping !== '') {
            if ('_default_' === $mapping || (count($mappings) === 1 && isset($mappings['_default_']))) {
                throw new InvalidConfigException('It is forbidden to index into the default mapping.');
            }
            if (!isset($mappings[$mapping])) {
                throw new InvalidConfigException("`{$mapping}` mapping is not configured in `{$index}`");
            }
        }

        return $mapping;
    }
}
