<?php

declare(strict_types=1);

namespace App\Services\Ontology;

use App\Data\DestinationRdfDocument;
use DOMDocument;
use Illuminate\Support\Facades\Cache;
use JsonException;

final class DestinationRdfExporter
{
    public function __construct(
        private readonly FusekiClient $client,
        private readonly DestinationRdfQueryBuilder $queryBuilder,
        private readonly SparqlEscaper $escaper,
        private readonly OntologyDataVersion $dataVersion,
    ) {}

    public function export(string $id, string $format): ?DestinationRdfDocument
    {
        $formats = config('ontology.rdf_export.formats');
        $formatConfig = is_array($formats) ? ($formats[$format] ?? null) : null;
        if (! is_array($formatConfig)
            || ! is_string($formatConfig['media_type'] ?? null)
            || ! is_string($formatConfig['extension'] ?? null)) {
            throw new \InvalidArgumentException('Format ekspor RDF tidak didukung.');
        }

        $version = $this->version();
        $cacheKey = 'ontology.rdf-export.'.hash('sha256', $version.'|'.$id.'|'.$format);
        $body = Cache::get($cacheKey);

        if (! is_string($body) || trim($body) === '') {
            $body = $this->client->construct(
                $this->queryBuilder->constructDepthOne($id),
                $formatConfig['media_type'],
            );

            if ($body === null || $this->isEmptyGraph($body, $format)) {
                return null;
            }

            if ((int) config('ontology.rdf_export.cache_ttl', 600) > 0) {
                Cache::put($cacheKey, $body, (int) config('ontology.rdf_export.cache_ttl', 600));
            }
        }

        return new DestinationRdfDocument(
            body: $body,
            mediaType: $formatConfig['media_type'],
            extension: $formatConfig['extension'],
            etag: '"'.hash('sha256', $version.'|'.$id.'|'.$format).'"',
            maxAge: (int) config('ontology.rdf_export.cache_ttl', 600),
        );
    }

    private function isEmptyGraph(string $body, string $format): bool
    {
        if (trim($body) === '') {
            return true;
        }

        return match ($format) {
            'ttl' => $this->hasTurtleStatements($body) === false,
            'rdf' => $this->hasRdfXmlStatements($body) === false,
            'jsonld' => $this->hasJsonLdStatements($body) === false,
            default => true,
        };
    }

    private function hasTurtleStatements(string $body): bool
    {
        $statements = preg_replace(
            '/^[ \t]*(?:PREFIX|@prefix)[ \t]+\S+:[ \t]*<[^>]+>[ \t]*\.?[ \t]*(?:\r?\n|$)/im',
            '',
            $body,
        );

        return is_string($statements) && trim($statements) !== '';
    }

    private function hasRdfXmlStatements(string $body): bool
    {
        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        if (! $document->loadXML($body, LIBXML_NONET) || $document->documentElement === null) {
            throw OntologyUnavailableException::badResponse();
        }

        foreach ($document->documentElement->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                return true;
            }
        }

        return false;
    }

    private function hasJsonLdStatements(string $body): bool
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw OntologyUnavailableException::badResponse($exception);
        }

        if (! is_array($data)) {
            return false;
        }

        if (array_is_list($data)) {
            return $data !== [];
        }

        return isset($data['@id'])
            || (isset($data['@graph']) && is_array($data['@graph']) && $data['@graph'] !== []);
    }

    private function version(): string
    {
        return $this->dataVersion->current() ?? 'initial';
    }
}
