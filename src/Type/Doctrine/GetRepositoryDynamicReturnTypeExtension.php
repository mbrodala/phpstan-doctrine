<?php declare(strict_types = 1);

namespace PHPStan\Type\Doctrine;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Generic\GenericClassStringType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeWithClassName;

class GetRepositoryDynamicReturnTypeExtension implements \PHPStan\Type\DynamicMethodReturnTypeExtension
{

	/** @var string */
	private $managerClass;

	/** @var ObjectMetadataResolver */
	private $metadataResolver;

	public function __construct(
		string $managerClass,
		ObjectMetadataResolver $metadataResolver
	)
	{
		$this->managerClass = $managerClass;
		$this->metadataResolver = $metadataResolver;
	}

	public function getClass(): string
	{
		return $this->managerClass;
	}

	public function isMethodSupported(MethodReflection $methodReflection): bool
	{
		return $methodReflection->getName() === 'getRepository';
	}

	public function getTypeFromMethodCall(
		MethodReflection $methodReflection,
		MethodCall $methodCall,
		Scope $scope
	): Type
	{
		if (count($methodCall->getArgs()) === 0) {
			return new GenericObjectType(
				$this->metadataResolver->getResolvedRepositoryClass(),
				[new ObjectWithoutClassType()]
			);
		}
		$argType = $scope->getType($methodCall->getArgs()[0]->value);
		if ($argType instanceof ConstantStringType) {
			$objectName = $argType->getValue();
			$classType = new ObjectType($objectName);
		} elseif ($argType instanceof GenericClassStringType) {
			$classType = $argType->getGenericType();
			if (!$classType instanceof TypeWithClassName) {
				return new GenericObjectType(
					$this->metadataResolver->getResolvedRepositoryClass(),
					[$classType]
				);
			}

			$objectName = $classType->getClassName();
		} else {
			return $this->getDefaultReturnType($scope, $methodCall->getArgs(), $methodReflection);
		}

		try {
			$repositoryClass = $this->metadataResolver->getRepositoryClass($objectName);
		} catch (\Doctrine\ORM\Mapping\MappingException $e) {
			return $this->getDefaultReturnType($scope, $methodCall->getArgs(), $methodReflection);
		}

		return new GenericObjectType($repositoryClass, [
			$classType,
		]);
	}

	/**
	 * @param \PHPStan\Analyser\Scope $scope
	 * @param \PhpParser\Node\Arg[] $args
	 * @param \PHPStan\Reflection\MethodReflection $methodReflection
	 * @return \PHPStan\Type\Type
	 */
	private function getDefaultReturnType(Scope $scope, array $args, MethodReflection $methodReflection): Type
	{
		$defaultType = ParametersAcceptorSelector::selectFromArgs(
			$scope,
			$args,
			$methodReflection->getVariants()
		)->getReturnType();
		if ($defaultType instanceof GenericObjectType && count($defaultType->getTypes()) > 0) {
			return new GenericObjectType(
				$this->metadataResolver->getResolvedRepositoryClass(),
				[$defaultType->getTypes()[0]]
			);
		}

		return $defaultType;
	}

}
