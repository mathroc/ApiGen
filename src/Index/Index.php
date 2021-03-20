<?php declare(strict_types = 1);

namespace ApiGenX\Index;

use ApiGenX\Info\ClassInfo;
use ApiGenX\Info\ClassLikeInfo;
use ApiGenX\Info\InterfaceInfo;
use ApiGenX\Info\TraitInfo;


final class Index
{
	/** @var FileIndex[] indexed by [filePath] */
	public array $files = [];

	/** @var NamespaceIndex[] indexed by [namespaceName] */
	public array $namespace = [];

	/** @var ClassLikeInfo[] indexed by [classLikeName] */
	public array $classLike = [];

	/** @var ClassInfo[] indexed by [className] */
	public array $class = [];

	/** @var InterfaceInfo[] indexed by [interfaceName] */
	public array $interface = [];

	/** @var TraitInfo[] indexed by [traitName] */
	public array $trait = [];

	/** @var ClassInfo[][] indexed by [classLikeName][classLikeName] */
	public array $classExtends = [];

	/** @var ClassInfo[][] indexed by [classLikeName][classLikeName] */
	public array $classImplements = [];

	/** @var ClassInfo[][] indexed by [classLikeName][classLikeName] */
	public array $classUses = [];

	/** @var InterfaceInfo[][] indexed by [classLikeName][classLikeName] */
	public array $interfaceExtends = [];

	/** @var ClassLikeInfo[][] indexed by [classLikeName][classLikeName], e.g. ['a']['b'] means that B instance of A */
	public array $instanceOf = [];

	/** @var ClassLikeInfo[][] indexed by [classLikeName][classLikeName] instance of + classUses */
	public array $tree = [];

	/** @var ClassLikeInfo[][] indexed by [constantName][] */
	public array $constants = [];

	/** @var ClassLikeInfo[][] indexed by [propertyName][] */
	public array $properties = [];

	/** @var ClassLikeInfo[][] indexed by [methodName][] */
	public array $methods = [];

	/** @var ClassLikeInfo[][][] indexed by [classLikeName][methodName], e.g. ['a']['b'] = [C] means method A::b is overriding C::b */
	public array $methodOverrides = [];

	/** @var ClassLikeInfo[][][] indexed by [classLikeName][methodName][], e.g. ['c']['b'] = [A] means method C::b is overridden by A::b */
	public array $methodOverriddenBy = [];

	/** @var ClassLikeInfo[][][] indexed by [classLikeName][methodName], e.g. ['a']['b'] = [C] means method A::b is implementing C::b */
	public array $methodImplements = [];

	/** @var ClassLikeInfo[][][] indexed by [classLikeName][methodName][], e.g. ['c']['b'] = [A] means method C::b is implemented by A::b */
	public array $methodImplementedBy = [];
}
