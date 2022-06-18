<?php declare(strict_types = 1);

namespace ApiGenX\Analyzer\NodeVisitors;

use ApiGenX\Analyzer\IdentifierKind;
use ApiGenX\Info\GenericParameterInfo;
use ApiGenX\Info\GenericParameterVariance;
use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ThrowsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;

use function array_pop;
use function count;
use function get_class;
use function str_ends_with;
use function strtolower;
use function substr;


final class PhpDocResolver extends NodeVisitorAbstract
{
	private const KEYWORDS = [
		'int' => true, 'integer' => true, 'string' => true, 'bool' => true, 'boolean' => true, 'true' => true,
		'false' => true, 'null' => true, 'float' => true, 'double' => true, 'array' => true, 'scalar' => true,
		'number' => true, 'iterable' => true, 'callable' => true, 'resource' => true, 'mixed' => true,
		'void' => true, 'object' => true, 'never' => true, 'self' => true, 'static' => true, 'parent' => true,
		'class-string' => true, 'list' => true,
	];

	/** @var GenericParameterInfo[][] indexed by [][parameterName] */
	private array $genericNameContextStack = [];


	public function __construct(
		private Lexer $lexer,
		private PhpDocParser $parser,
		private NameContext $nameContext,
	) {
	}


	public function enterNode(Node $node): null|int|Node
	{
		$doc = $node->getDocComment();

		if ($doc !== null) {
			$tokens = $this->lexer->tokenize($doc->getText());
			$phpDoc = $this->parser->parse(new TokenIterator($tokens));

			if ($node instanceof Node\Stmt\ClassLike || $node instanceof Node\FunctionLike) {
				$genericNameContext = $this->resolveGenericNameContext($phpDoc);
				$phpDoc->setAttribute('genericNameContext', $genericNameContext);
				$this->genericNameContextStack[] = $genericNameContext;
			}

			$this->resolvePhpDoc($phpDoc);
			$node->setAttribute('phpDoc', $phpDoc);

		} elseif ($node instanceof Node\Stmt\ClassLike || $node instanceof Node\FunctionLike) {
			$this->genericNameContextStack[] = [];
		}

		return null;
	}


	public function leaveNode(Node $node): null|int|Node|array
	{
		if ($node instanceof Node\Stmt\ClassLike || $node instanceof Node\FunctionLike) {
			if (array_pop($this->genericNameContextStack) === null) {
				throw new \LogicException();
			}
		}

		return null;
	}


	/**
	 * @return GenericParameterInfo[] indexed by [parameterName]
	 */
	private function resolveGenericNameContext(PhpDocNode $doc): array
	{
		$context = [];

		foreach ($doc->children as $child) {
			if ($child instanceof PhpDocTagNode && $child->value instanceof TemplateTagValueNode) {
				$lower = strtolower($child->value->name);
				$variance = str_ends_with($child->name, '-covariant') ? GenericParameterVariance::Covariant : GenericParameterVariance::Invariant;
				$context[$lower] = new GenericParameterInfo($child->value->name, $variance, $child->value->bound, $child->value->description);
			}
		}

		return $context;
	}


	/**
	 * @return iterable<TypeNode>
	 */
	public static function getTypes(PhpDocNode $phpDocNode): iterable
	{
		foreach ($phpDocNode->getTags() as $tag) {
			switch (get_class($tag->value)) {
				case ParamTagValueNode::class:
				case PropertyTagValueNode::class:
				case ReturnTagValueNode::class:
				case ThrowsTagValueNode::class:
				case VarTagValueNode::class:
					yield $tag->value->type;
					break;

				case MethodTagValueNode::class:
					if ($tag->value->returnType !== null) {
						yield $tag->value->returnType;
					}

					foreach ($tag->value->parameters as $parameter) {
						if ($parameter->type !== null) {
							yield $parameter->type;
						}
					}
					break;
			}
		}
	}


	/**
	 * @return iterable<ConstExprNode>
	 */
	public static function getExpressions(PhpDocNode $phpDocNode): iterable
	{
		foreach ($phpDocNode->getTags() as $tag) {
			if ($tag->value instanceof MethodTagValueNode) {
				foreach ($tag->value->parameters as $parameter) {
					if ($parameter->defaultValue) {
						yield $parameter->defaultValue;
					}
				}
			}
		}
	}


	/**
	 * @return iterable<IdentifierTypeNode>
	 */
	public static function getIdentifiers(TypeNode $typeNode): iterable
	{
		if ($typeNode instanceof IdentifierTypeNode) {
			yield $typeNode;

		} elseif ($typeNode instanceof NullableTypeNode || $typeNode instanceof ArrayTypeNode) {
			yield from self::getIdentifiers($typeNode->type);

		} elseif ($typeNode instanceof UnionTypeNode || $typeNode instanceof IntersectionTypeNode) {
			foreach ($typeNode->types as $innerType) {
				yield from self::getIdentifiers($innerType);
			}

		} elseif ($typeNode instanceof GenericTypeNode) {
			yield from self::getIdentifiers($typeNode->type);
			foreach ($typeNode->genericTypes as $innerType) {
				yield from self::getIdentifiers($innerType);
			}

		} elseif ($typeNode instanceof CallableTypeNode) {
			yield $typeNode->identifier;
			yield from self::getIdentifiers($typeNode->returnType);

			foreach ($typeNode->parameters as $parameter) {
				yield from self::getIdentifiers($parameter->type);
			}

		} elseif ($typeNode instanceof ArrayShapeNode) {
			foreach ($typeNode->items as $item) {
				yield from self::getIdentifiers($item->valueType);
			}
		}
	}


	private function resolvePhpDoc(PhpDocNode $phpDoc): void
	{
		foreach (self::getTypes($phpDoc) as $type) {
			foreach (self::getIdentifiers($type) as $identifier) {
				$lower = strtolower($identifier->name);

				if (isset(self::KEYWORDS[$lower])) {
					$identifier->setAttribute('kind', IdentifierKind::Keyword);
					continue;
				}

				for ($i = count($this->genericNameContextStack) - 1; $i >= 0; $i--) {
					if (isset($this->genericNameContextStack[$i][$lower])) {
						$identifier->setAttribute('kind', IdentifierKind::Generic);
						continue 2;
					}
				}

				$identifier->name = $this->resolveIdentifier($identifier->name);
				$identifier->setAttribute('kind', IdentifierKind::ClassLike);
			}
		}

		foreach (self::getExpressions($phpDoc) as $expr) {
			if ($expr instanceof ConstFetchNode && $expr->className !== '') {
				$expr->className = $this->resolveIdentifier($expr->className);
			}
		}
	}


	private function resolveIdentifier(string $identifier): string
	{
		if ($identifier[0] === '\\') {
			return substr($identifier, 1);

		} else {
			return $this->nameContext->getResolvedClassName(new Node\Name($identifier))->toString();
		}
	}
}
