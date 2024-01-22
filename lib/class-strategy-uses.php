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
class Strategy_Uses extends AbstractFactory {

	private PrettyPrinter $valueConverter;

	/**
	 * Initializes the object.
	 */
	public function __construct(
		DocBlockFactoryInterface $docBlockFactory,
		PrettyPrinter $prettyPrinter
	) {
		parent::__construct($docBlockFactory);
		$this->valueConverter = $prettyPrinter;
	}

	public function matches( ContextStack $context, object $object ): bool {
		return ( $object instanceof Expression ) &&
		(
			$object->expr instanceof FuncCall ||
			$object->expr instanceof MethodCall ||
			$object->expr instanceof StaticCall
		);
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

		$what = $context->peek();

		$what->uses   ??= [];
		$what->uses['functions'] ??= [];
		$what->uses['methods']   ??= [];

		if (
			$object->expr instanceof FuncCall &&
			$object->expr->name instanceof Name
		) {

			/*

			function test() { a_function(); }

			$what = phpDocumentor\Reflection\Php\Function_  => test()
			$object = PhpParser\Node\Stmt\Expression  ->expr PhpParser\Node\Expr\FuncCall ->name a_function

			*/

			$function_called = $object->expr;

			$what->uses['functions'][] = $function_called;
		} elseif (
			$object->expr instanceof MethodCall ||
			$object->expr instanceof StaticCall
		) {
			/*

			My_Class::a_method() calling $this->do_it();
			
			what = phpDocumentor\Reflection\Php\Method ->fqsen \My_Class::a_method(), 
			object->expr = PhpParser\Node\Expr\MethodCall,  ->var = $this, ->name = do_it
			
			*/

			$class = '';
			if (
				$object->expr instanceof MethodCall &&
				$object->expr->var instanceof Variable &&
				'this' === (string) $object->expr->var->name
			) {
				// Find the classname of $this...
				$class = explode( '::', (string) $what->getFqsen() )[0];
				// OK
			} elseif (
				$object->expr instanceof MethodCall &&
				$object->expr->var instanceof Variable
			) {
				// TODO: This seems wrong, but the unit tests say this.
				$class = '$' . (string) $object->expr->var->name;
			} elseif (
				$object->expr instanceof MethodCall &&
				$object->expr->var instanceof FuncCall
			) {
				// TODO: This seems wrong, but the unit tests say this.
				$class = (string) $object->expr->var->name . '()';
			} elseif (
				$object->expr instanceof StaticCall &&
				$object->expr->class instanceof Name
				//$object->expr instanceof MethodCall
			) {
				// TODO: Figure out the namespace for this call...
				$class = '\\' . (string) $object->expr->class;
				if ( $class === '\self' ) {
					// Self needs to know the current class we're in.
					$search = $context->search( Class_::class );
					if ( $search ) {
						$class = (string) $search->getFqsen();
					}
				}
				if ( $class === '\parent' ) {
					$search = $context->search( Class_::class );
					if ( $search ) {
						$class = (string) $search->getParent();

					}
				}
			}

			//var_dump( compact( 'what', 'object', 'class' ) ); die();
			$what->uses['methods'][] = [ $class, $object->expr ];
		} else {
			var_dump( compact( 'what', 'object' ) ); die();
		}

	}

}
