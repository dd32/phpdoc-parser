<?php

namespace WP_Parser;

use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use phpDocumentor\Reflection\Element;
use phpDocumentor\Reflection\Fqsen;
use phpDocumentor\Reflection\Location;
use phpDocumentor\Reflection\Metadata\MetaDataContainer as MetaDataContainerInterface;
use phpDocumentor\Reflection\Php\Argument;
use phpDocumentor\Reflection\Php\Factory\AbstractFactory;
use phpDocumentor\Reflection\Php\Factory\ContextStack;
use phpDocumentor\Reflection\Php\Class_;
use phpDocumentor\Reflection\Php\File as FileElement;
use phpDocumentor\Reflection\Php\StrategyContainer;
use phpDocumentor\Reflection\Php\MetadataContainer;
use phpDocumentor\Reflection\Types\Mixed_;
use PhpParser\Node\Expr\Assign;

use PhpParser\Node\Expr\FuncCall;
//PhpParser\Node\Expr\New_ // TODO
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
//PhpParser\Node\Expr\NullsafeMethodCall // TODO

use PhpParser\Node\Expr\Variable;



use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Strategy to convert WP Hooks expressions to ConstantElement
 *
 * @see ConstantElement
 * @see GlobalConstantIterator
 */
class Strategy_PHPDoc_Parser extends AbstractFactory {

	private $our_callbacks = [];

	/**
	 * Initializes the object.
	 */
	public function __construct(
		DocBlockFactoryInterface $docBlockFactory,
		PrettyPrinter $prettyPrinter,
		$our_callbacks = []
	) {
		parent::__construct($docBlockFactory);

		$this->our_callbacks = $our_callbacks;
	}

	public function matches( ContextStack $context, object $object ): bool {
		foreach ( $this->our_callbacks as $c ) {
			if ( $c->matches( $context, $object ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Creates an Constant out of the given object.
	 *
	 * Since an object might contain other objects that need to be converted the $factory is passed so it can be
	 * used to create nested Elements.
	 *
	 * @param Expression $object object to convert to an Element
	 * @param StrategyContainer $strategies used to convert nested objects.
	 */
	protected function doCreate(
		ContextStack $context,
		object $object,
		StrategyContainer $strategies
	): void {
		foreach ( $this->our_callbacks as $c ) {
			if ( $c->matches( $context, $object ) ) {
				$c->doCreate( $context, $object, $strategies );
			}
		}
	}

}
