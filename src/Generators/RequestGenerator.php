<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Response;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Crescat\SaloonSdkGenerator\Generator;
use Crescat\SaloonSdkGenerator\Helpers\MethodGeneratorHelper;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
use DateTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method as SaloonHttpMethod;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Saloon\Traits\Request\CreatesDtoFromResponse;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;

class RequestGenerator extends Generator
{
    private array $responseClasses = [];

    private DtoGenerator $dtoGenerator;

    public function __construct(Config $config)
    {
        parent::__construct($config);
        $this->dtoGenerator = new DtoGenerator($config);
    }

    public function generate(ApiSpecification $specification): PhpFile|array
    {
        $classes = [];

        foreach ($specification->endpoints as $endpoint) {

            foreach ($this->generateResponseDto($endpoint) as $class => $file) {
                $this->responseClasses[$class] = $classes[] = $file;
            }

            $classes[] = $this->generateRequestClass($endpoint);
        }

        return $classes;
    }

    private function createResponseName(Endpoint $endpoint, int $status): string
    {
        return Str::studly(NameHelper::normalize("{$endpoint->name}{$status}")) . "Response";
    }

    public function generateResponseDto(Endpoint $endpoint)
    {
        foreach ($endpoint->response as $status => $response) {
            if (in_array($status, [204])) {
                continue;
            }

            if ($response instanceof Reference) {
                continue;
            }

            $schema = array_values($response->content)[0]->schema ?? null;

            if ($schema instanceof Reference) {

            }

            if (!$schema instanceof \cebe\openapi\spec\Schema) {
                continue;
            }

            /** @var Schema[] $properties */
            $properties = $schema->properties ?? [];

            $dtoName = $this->createResponseName($endpoint, $status);
            $collection = Str::studly($endpoint->collection ?? 'Default');

            $classType = new ClassType($dtoName);
            $classType->setFinal()->setReadOnly();
            
            $classFile = new PhpFile;
            $classFile->setStrictTypes();
            $namespace = $classFile
                ->addNamespace("{$this->config->namespace}\\Response\\{$collection}");

            $classType->setExtends(Data::class)
                ->setComment($schema->title ?? '')
                ->addComment('')
                ->addComment(Utils::wrapLongLines($schema->description ?? ''));

            $classConstructor = $classType->addMethod('__construct');

            $generatedMappings = false;
            $referencedDtos = [];

            foreach ($properties as $propertyName => $propertySpec) {
                if (trim($propertyName) === '') {
                    continue;
                }

                $type = $this->convertOpenApiTypeToPhp($propertySpec);

                // Check if this is a reference to another schema
                if ($propertySpec instanceof Reference) {
                    // For references, we need to use the DTO class name
                    // The schema name from the reference is already the base name (e.g., "User")
                    // We need to apply the same transformation as we do for the DTO class names
                    $schemaName = $type;
                    $dtoClassName = NameHelper::dtoClassName($schemaName);
                    // Use the FQN for the type
                    $type = "{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$dtoClassName}";
                    // Track referenced DTOs
                    $referencedDtos[] = $dtoClassName;
                }

                $sub = NameHelper::dtoClassName($type);

                if ($type === 'object' || $type == 'array') {

                    if (!isset($this->generated[$sub]) && !empty($propertySpec->properties)) {
                        $this->generated[$sub] = $this->dtoGenerator->generateDtoClass($propertyName, $propertySpec);
                    }
                }

                $name = NameHelper::safeVariableName($propertyName);

                $property = $classConstructor->addPromotedParameter($name)
                    ->setPublic()
                    ->setDefaultValue(null);

                // Set the property type
                $property->setType($type);

                if ($name != $propertyName) {
                    $property->addAttribute(MapName::class, [$propertyName]);
                    $generatedMappings = true;
                }
            }

            $namespace->addUse(Data::class, alias: 'SpatieData');

            if ($generatedMappings) {
                // $namespace->addUse(MapName::class);
            }


            $namespace->add($classType);

            yield $dtoName => $classFile;
        }
    }

    protected function generateRequestClass(Endpoint $endpoint): PhpFile
    {
        // $schemas = collect($endpoint->response ?? [])
        //     ->flatMap(fn (Response $response) => array_values($response->content))
        //     ->flatMap(
        //         fn (MediaType $mediaType) => $mediaType->schema instanceof Reference
        //             ? $mediaType->schema
        //             : ($mediaType->schema->properties ?? $mediaType->schema->items ?? [])
        //     )
        //     // ->filter(fn ($properties) => $properties instanceof Reference)
        //     ->toArray();

        $resourceName = NameHelper::resourceClassName($endpoint->collection ?: $this->config->fallbackResourceName);
        $className = NameHelper::requestClassName($endpoint->name);

        $classType = new ClassType($className);

        $classFile = new PhpFile;
        $namespace = $classFile
            ->addNamespace("{$this->config->namespace}\\{$this->config->requestNamespaceSuffix}\\{$resourceName}");

        $classType->setExtends(Request::class)
            ->setComment($endpoint->name)
            ->addComment('')
            ->addComment(Utils::wrapLongLines($endpoint->description ?? ''));

        // TODO: We assume JSON body if post/patch, make these assumptions configurable in the future.
        if ($endpoint->method->isPost() || $endpoint->method->isPatch()) {
            $classType
                ->addImplement(HasBody::class)
                ->addTrait(HasJsonBody::class);

            $namespace
                ->addUse(HasBody::class)
                ->addUse(HasJsonBody::class);
        }

        if (count(array_filter(array_keys($endpoint->response), fn(int $status) => $status !== 204)) > 0) {
            $classType->addTrait(CreatesDtoFromResponse::class);
            $namespace->addUse(CreatesDtoFromResponse::class);
            $namespace->addUse(\Saloon\Http\Response::class);
            $types = [];

            $collection = Str::studly($endpoint->collection ?? 'Default');


            foreach (array_keys($endpoint->response) as $status) {
                if ($status === 204) {
                    continue;
                }
                if (!isset($this->responseClasses[$this->createResponseName($endpoint, $status)])) {
                    // continue;
                }
                // $types[] = $type = "{$this->config->namespace}\\Response\\{$collection}\\{$this->createResponseName($endpoint, $status)}";

                // $namespace->addUse($type);

                $schema = array_values($endpoint->response[$status]->content ?? [])[0]->schema ?? null;

                if ($schema instanceof Reference) {
                    $name = Str::afterLast($schema->getReference(), '/');

                    $dtoClassName = NameHelper::dtoClassName($name);
                    // Use the FQN for the type
                    $types[] = $type = "{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$dtoClassName}";

                    $namespace->addUse(
                        $type
                    );
                } else {
                    
                    
                    $collection = Str::studly($endpoint->collection ?? 'Default');

                    $types[] = $type = "{$this->config->namespace}\\Response\\{$collection}\\{$this->createResponseName($endpoint, $status)}";

                    $namespace->addUse(
                        $type
                    );
                }
            }

            $typesString = implode('|', $types);
            $typesStringFqcnImport = implode('|', array_map(class_basename(...), $types));

            $classType->addComment("@extends Request<{$typesStringFqcnImport}>");

            $dtoMethod = $classType->addMethod('createDtoFromResponse')
                ->setPublic()
                ->setReturnType($typesString);

            $dtoMethod->addParameter('response')->setType(\Saloon\Http\Response::class);

            if (count($endpoint->response) > 0) {
                $dtoMethod->addBody(
                    new Literal("return match(\$response->status()) {"),
                );

                foreach (array_keys($endpoint->response) as $status) {
                    if ($status === 204) {
                        continue;
                    }

                    $schema = array_values($endpoint->response[$status]->content ?? [])[0]->schema ?? null;
                    $dtoClass = $schema instanceof Reference
                        ? NameHelper::dtoClassName($name)
                        : $this->createResponseName($endpoint, $status);


                    $dtoMethod->addBody(
                        new Literal("\t{$status} => {$dtoClass}::from(\$response->json()),")
                    );
                }
        
                $dtoMethod->addBody(
                    new Literal("};"),
                );
            }

            $responses = implode('|', array_map(
                fn(int $status) => $this->createResponseName($endpoint, $status),
                array_keys($endpoint->response)
            ));



            // $dtoMethod->addComment('@return ' . $responses);
        }

        $classType->addProperty('method')
            ->setProtected()
            ->setType(SaloonHttpMethod::class)
            ->setValue(
                new Literal(
                    sprintf('Method::%s', $endpoint->method->value)
                )
            );

        $classType->addMethod('resolveEndpoint')
            ->setPublic()
            ->setReturnType('string')
            ->addBody(
                collect($endpoint->pathSegments)
                    ->map(function ($segment) {
                        return Str::startsWith($segment, ':')
                            ? new Literal(sprintf('{$this->%s}', NameHelper::safeVariableName($segment)))
                            : $segment;
                    })
                    ->pipe(function (Collection $segments) {
                        return new Literal(sprintf('return "/%s";', $segments->implode('/')));
                    })

            );

        $classConstructor = $classType->addMethod('__construct');

        // Priority 1. - Path Parameters
        foreach ($endpoint->pathParameters as $pathParam) {
            MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $pathParam);
        }

        // Priority 2. - Body Parameters
        if (!empty($endpoint->bodyParameters)) {
            $bodyParams = collect($endpoint->bodyParameters)
                ->reject(fn(Parameter $parameter) => in_array($parameter->name, $this->config->ignoredBodyParams))
                ->values()
                ->toArray();

            foreach ($bodyParams as $bodyParam) {
                MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $bodyParam);
            }

            MethodGeneratorHelper::generateArrayReturnMethod($classType, 'defaultBody', $bodyParams, withArrayFilterWrapper: true);
        }

        // Priority 3. - Query Parameters
        if (!empty($endpoint->queryParameters)) {
            $queryParams = collect($endpoint->queryParameters)
                ->reject(fn(Parameter $parameter) => in_array($parameter->name, $this->config->ignoredQueryParams))
                ->values()
                ->toArray();

            foreach ($queryParams as $queryParam) {
                MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $queryParam);
            }

            MethodGeneratorHelper::generateArrayReturnMethod($classType, 'defaultQuery', $queryParams, withArrayFilterWrapper: true);
        }

        // Priority 4. - Header Parameters
        if (!empty($endpoint->headerParameters)) {
            $headerParams = collect($endpoint->headerParameters)
                ->reject(fn(Parameter $parameter) => in_array($parameter->name, $this->config->ignoredHeaderParams))
                ->values()
                ->toArray();

            foreach ($headerParams as $headerParam) {
                MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $headerParam);
            }

            MethodGeneratorHelper::generateArrayReturnMethod($classType, 'defaultHeaders', $headerParams, withArrayFilterWrapper: true);
        }

        $namespace
            ->addUse(SaloonHttpMethod::class)
            ->addUse(DateTime::class)
            ->addUse(Request::class)
            ->add($classType);

        return $classFile;
    }

    protected function convertOpenApiTypeToPhp(\cebe\openapi\spec\Schema|Reference $schema)
    {
        if ($schema instanceof Reference) {
            return Str::afterLast($schema->getReference(), '/');
        }

        if (is_array($schema->type)) {
            return collect($schema->type)->map(fn($type) => $this->mapType($type))->implode('|');
        }

        if (is_string($schema->type)) {
            return $this->mapType($schema->type, $schema->format);
        }

        return 'mixed';
    }

    protected function mapType($type, $format = null): string
    {
        return match ($type) {
            'integer' => 'int',
            'string' => 'string',
            'boolean' => 'bool',
            'object' => 'object', // Recurse
            'number' => match ($format) {
                    'float' => 'float',
                    'int32', 'int64	' => 'int',
                    default => 'int|float',
                },
            'array' => 'array',
            'null' => 'null',
            default => 'mixed',
        };
    }
}
