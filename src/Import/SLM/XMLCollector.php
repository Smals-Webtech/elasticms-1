<?php

namespace App\Import\SLM;

use App\Import\SLM\Document\Client;
use App\Import\SLM\Document\CSM;
use App\Import\SLM\Document\SLA;
use Symfony\Component\DomCrawler\Crawler;

class XMLCollector
{
    /** @var array<mixed> */
    private $collectionCSM = [];
    /** @var array<mixed> */
    private $collectionSLA = [];
    /** @var array<mixed> */
    private $collectionClients = [];

    const MAPPING_CLIENT = [
        'ClientNR' => 'id',
        'Client_NR' => 'id',
        'ClientSeq' => 'sequence',
        'ClientName' => 'name',
    ];

    const MAPPING_CSM = [
        'CSMAcronyme' => 'acronym',
        'CSM_Acronym' => 'acronym',
        'fullname' => 'fullname',
        'email' => 'email',
    ];

    const MAPPING_SLA = [
        'SLA_NR' => 'id',
        'Status' => 'sla_status',
        'service_name' => 'service_name',
        'ServiceName' => 'service_name',
        'Service_FR' => 'sla_service_fr',
        'Service_NL' => 'sla_service_nl',
        'SLA_doc' => 'sla_doc',
        'CSMAcronyme' => 'csm_acronym',
        'CSM_Acronym' => 'csm_acronym',
        'Client_NR' => 'client_id',
        'ClientNR' => 'client_id',
        'ClientName' => 'client_name',
    ];

    /**
     * @param array<mixed> $xml
     */
    public function collect(array $xml): void
    {
        $crawler = new Crawler();
        $fileContent = \file_get_contents($xml['file']);
        if (false === $fileContent) {
            throw new \RuntimeException(\sprintf('Unexpected false on file_get_contents(""%s)', $fileContent));
        }
        $crawler->addXmlContent($fileContent);
        $elements = $crawler->filterXPath('//default:Detail');

        foreach ($elements as $element) {
            $client = $this->getElementData($element, self::MAPPING_CLIENT);
            $csm = $this->getElementData($element, self::MAPPING_CSM);
            $sla = $this->getElementData($element, self::MAPPING_SLA);

            if (!isset($sla['service_name'])) {
                continue;
            }

            $this->add('collectionClients', $client['id'], $client);
            $this->add('collectionCSM', $csm['acronym'], $csm);
            $this->add('collectionSLA', $sla['id'], $sla);
        }
    }

    /**
     * @return array<mixed>
     */
    public function getClients(): array
    {
        return \array_map(function (array $client) {
            return new Client($client['id'], $client['name'], $client['sequence'] ?? null);
        }, $this->collectionClients);
    }

    /**
     * @return array<mixed>
     */
    public function getCSMs(): array
    {
        return \array_map(function (array $csm) {
            return new CSM($csm['acronym'], $csm['fullname'] ?? null, $csm['email'] ?? null);
        }, $this->collectionCSM);
    }

    /**
     * @return array<mixed>
     */
    public function getSLAs(): array
    {
        return \array_map(function (array $sla) {
            return new SLA($sla);
        }, $this->collectionSLA);
    }

    /**
     * @param array<mixed> $mapping
     *
     * @return array<mixed>
     */
    private function getElementData(\DOMNode $element, array $mapping): array
    {
        $data = [];

        foreach ($mapping as $attr => $property) {
            if (!$element instanceof \DOMElement) {
                continue;
            }
            $value = $element->getAttribute($attr);

            if (!isset($data[$property]) && null != $value) {
                $data[$property] = $value;
            }
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    private function add(string $collection, string $id, array $data): void
    {
        if (\array_key_exists($id, $this->{$collection})) {
            $this->{$collection}[$id] = \array_merge($this->{$collection}[$id], $data);
        } else {
            $this->{$collection}[$id] = $data;
        }
    }
}
