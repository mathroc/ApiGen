<?php declare(strict_types = 1);

require __DIR__ . '/../vendor/autoload.php';

use ApiGen\Analyzer;
use ApiGen\Info\NameInfo;
use Nette\Neon\Node;
use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use Tester\Assert;
use Tester\TestCase;


/**
 * @testCase
 * @phpIni short_open_tag = 1
 */
class AnalyzerTest extends TestCase
{
	/**
	 * @dataProvider provideSnapshotsData
	 */
	public function testSnapshots(SplFileInfo $file): void
	{
		$analyzer = $this->createAnalyzer();
		$result = $analyzer->processTask(new ApiGen\Analyzer\AnalyzeTask($file->getRealPath(), primary: true));
		$serialized = self::dump($result) . "\n";
		$serialized = str_replace(dirname(__DIR__), '%rootDir%', $serialized);

		$output = "{$file->getPath()}/{$file->getBasename('.php')}.neon";

		if (is_file($output) || getenv('CI')) {
			$actual = $serialized;
			$expected = FileSystem::read($output);
			Assert::same($expected, $actual);

		} else {
			FileSystem::write($output, $serialized);
		}
	}


	public function provideSnapshotsData(): iterable
	{
		foreach (Finder::findFiles('*.php')->from(__DIR__ . '/Features', __DIR__ . '/Issues') as $file) {
			yield $file->getFilename() => [$file];
		}
	}


	private function createAnalyzer(): Analyzer
	{
		$locator = new ApiGen\Locator([], new Composer\Autoload\ClassLoader());
		$phpParserFactory = new PhpParser\ParserFactory();
		$phpParser = $phpParserFactory->create(PhpParser\ParserFactory::PREFER_PHP7);

		$traverser = new PhpParser\NodeTraverser();
		$bodySkipper = new ApiGen\Analyzer\NodeVisitors\BodySkipper();
		$nameResolver = new PhpParser\NodeVisitor\NameResolver();

		$phpDocLexer = new PHPStan\PhpDocParser\Lexer\Lexer();
		$phpDocExprParser = new PHPStan\PhpDocParser\Parser\ConstExprParser();
		$phpDocTypeParser = new PHPStan\PhpDocParser\Parser\TypeParser($phpDocExprParser);
		$phpDocParser = new PHPStan\PhpDocParser\Parser\PhpDocParser($phpDocTypeParser, $phpDocExprParser);
		$phpDocResolver = new ApiGen\Analyzer\NodeVisitors\PhpDocResolver($phpDocLexer, $phpDocParser, $nameResolver->getNameContext());

		$traverser->addVisitor($bodySkipper);
		$traverser->addVisitor($nameResolver);
		$traverser->addVisitor($phpDocResolver);

		$filter = new ApiGen\Analyzer\Filter(excludeProtected: false, excludePrivate: true, excludeTagged: []);

		return new Analyzer($locator, $phpParser, $traverser, $filter);
	}


	private static function dump(mixed $value, string $indentation = ''): string
	{
		if ($value instanceof NameInfo) {
			return self::dump($value->full);
		}

		if (is_object($value)) {
			$s = '@' . $value::class . "(\n";
			$ref = new \ReflectionClass($value);

			foreach ($ref->getProperties() as $property) {
				$k = $property->getName();
				$v = $property->getValue($value);

				if (!$property->hasDefaultValue() || $property->getDefaultValue() !== $v) {
					$s .= "$indentation  $k: " . self::dump($v, $indentation . '  ') . "\n";
				}
			}

			$s .= "$indentation)";

			return $s;

		} elseif (is_array($value)) {
			if (array_is_list($value)) {
				if ($value === []) {
					return '[]';

				} else {
					$s = "[\n";

					foreach ($value as $item) {
						$s .= "$indentation  " . self::dump($item, $indentation . '  ') . ",\n";
					}

					$s .= "$indentation]";

					return $s;
				}

			} else {
				$s = '{';

				foreach ($value as $k => $v) {
					$s .= "\n$indentation  $k: " . self::dump($v, $indentation . '  ');
				}

				$s .= "\n$indentation}";

				return $s;
			}

		} elseif (is_string($value)) {
			return (new Node\StringNode($value))->toString();

		} else {
			return (new Node\LiteralNode($value))->toString();
		}
	}
}


Tester\Environment::setup();
(new AnalyzerTest)->run();