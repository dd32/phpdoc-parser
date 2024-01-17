<?php

namespace WP_Parser;

use phpDocumentor\Reflection\Php\Factory\AbstractFactory;
use phpDocumentor\Reflection\Php\Factory\ContextStack;

use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use phpDocumentor\Reflection\Element;
use phpDocumentor\Reflection\Fqsen;
use phpDocumentor\Reflection\Location;
use phpDocumentor\Reflection\Metadata\MetaDataContainer as MetaDataContainerInterface;
//use phpDocumentor\Reflection\Php\Constant as ConstantElement;
use phpDocumentor\Reflection\Php\Argument;
use phpDocumentor\Reflection\Php\File as FileElement;
use phpDocumentor\Reflection\Php\StrategyContainer;
use phpDocumentor\Reflection\Php\ValueEvaluator\ConstantEvaluator;
use phpDocumentor\Reflection\Php\MetadataContainer;
use phpDocumentor\Reflection\Php\Factory\Type as FactoryType;
use phpDocumentor\Reflection\Type;
use phpDocumentor\Reflection\Types\Mixed_;
use PhpParser\ConstExprEvaluationException;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;


use function assert;
use function sprintf;


/**
 * Strategy to convert WP Hooks expressions to ConstantElement
 *
 * @see ConstantElement
 * @see GlobalConstantIterator
 */
class Strategy_Hook extends AbstractFactory {
	protected $functions_to_match = [
		'apply_filters',
		'apply_filters_ref_array',
		'apply_filters_deprecated',
		'do_action',
		'do_action_ref_array',
		'do_action_deprecated',
	];

	private PrettyPrinter $valueConverter;

	private ConstantEvaluator $constantEvaluator;

	/**
	 * Initializes the object.
	 */
	public function __construct(
		DocBlockFactoryInterface $docBlockFactory,
		PrettyPrinter $prettyPrinter,
		?ConstantEvaluator $constantEvaluator = null
	) {
		parent::__construct($docBlockFactory);
		$this->valueConverter = $prettyPrinter;
		$this->constantEvaluator = $constantEvaluator ?? new ConstantEvaluator();
	}

	public function matches(ContextStack $context, object $object): bool {
		if ( ! $object instanceof Expression ) {
			return false;
		}

		$function = '';

		if (
			$object->expr instanceof FuncCall &&
			$object->expr->name instanceof Name
		) {
			$function = (string) $object->expr->name;
		} elseif (
			$object->expr instanceof Assign &&
			$object->expr->expr->name instanceof Name
		) {
			$function = (string) $object->expr->expr->name;
		}

		return $function && in_array(
			$function,
			$this->functions_to_match,
			true
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
		$expression = $object->expr;
		assert( $expression instanceof FuncCall || $expression instanceof Assign );

		if ( $expression instanceof FuncCall ) {
			[$name, $value] = $expression->args;
			$function       = $expression->name;
			$expr           = $object->expr;
		} elseif ( $expression instanceof Assign ) {
			[$name, $value] = $expression->expr->args;
			$function       = $expression->expr->name;
			$expr           = $object->expr->expr;
		}

		// We cannot calculate the name of a variadic consuming define.
		if ($name instanceof VariadicPlaceholder || $value instanceof VariadicPlaceholder) {
			// TODO: Does this apply?
			return;
		}

		$file = $context->search(FileElement::class);
		assert($file instanceof FileElement);

		// do_action(), apply_filters(), etc.
		$hook_name = $this->valueConverter->prettyPrintExpr( $name->value );
		$fqsen     = new Fqsen( '\\' . $function . '\\' . sha1( $hook_name ) );

		$hook = new Hook_(
			$fqsen,
			$hook_name,
			$this->createDocBlock($object->getDocComment(), $context->getTypeContext()),
			new Location($object->getLine()),
			new Location($object->getEndLine()),
			/* return type */
			/* return by ref */
		);

		foreach ( array_slice( $expr->args, 1 ) as $param ) {
			$hook->addArgument(
				new Argument(
					$this->valueConverter->prettyPrintExpr($param->value),
					new Mixed_, /* Can't have a type specified */
					null, /* Can't have a default value */
					$param->byRef,
					false, /*$param->variadic,*/
				)
			);
		}

		if ( ! isset( $file->uses ) ) {
			$file->uses = [];
		}
		if ( ! isset( $file->uses['hooks'] ) ) {
			$file->uses['hooks'] = [];
		}
		$file->uses['hooks'][] = $hook;
    }

}


// Based on Function_
class Hook_ implements Element, MetaDataContainerInterface {
    use MetadataContainer;

    /** @var Fqsen Full Qualified Structural Element Name */
    private Fqsen $fqsen;

    /** @var Argument[] */
    private array $arguments = [];

    private ?DocBlock $docBlock;

    private Location $location;

    private Location $endLocation;

    private Type $returnType;

    private bool $hasReturnByReference;

	public $hook_name = '';

    /**
     * Initializes the object.
     */
    public function __construct(
        Fqsen $fqsen,
		$hook_name,
        ?DocBlock $docBlock = null,
        ?Location $location = null,
        ?Location $endLocation = null,
        ?Type $returnType = null,
        bool $hasReturnByReference = false
    ) {
        if ($location === null) {
            $location = new Location(-1);
        }

        if ($endLocation === null) {
            $endLocation = new Location(-1);
        }

        if ($returnType === null) {
            $returnType = new Mixed_();
        }

		$this->fqsen                = $fqsen;
		$this->hook_name            = $hook_name;
        $this->docBlock             = $docBlock;
        $this->location             = $location;
        $this->endLocation          = $endLocation;
        $this->returnType           = $returnType;
        $this->hasReturnByReference = $hasReturnByReference;
    }

	public function getHookName() {
		return $this->cleanupName( $this->hook_name );
	}

	/**
	 * @param string $name
	 *
	 * @return string
	 */
	private function cleanupName( $name ) {
		$matches = array();

		// quotes on both ends of a string
		if ( preg_match( '/^[\'"]([^\'"]*)[\'"]$/', $name, $matches ) ) {
			return $matches[1];
		}

		// two concatenated things, last one of them a variable
		if ( preg_match(
			'/(?:[\'"]([^\'"]*)[\'"]\s*\.\s*)?' . // First filter name string (optional)
			'(\$[^\s]*)' .                        // Dynamic variable
			'(?:\s*\.\s*[\'"]([^\'"]*)[\'"])?/',  // Second filter name string (optional)
			$name, $matches ) ) {

			if ( isset( $matches[3] ) ) {
				return $matches[1] . '{' . $matches[2] . '}' . $matches[3];
			} else {
				return $matches[1] . '{' . $matches[2] . '}';
			}
		}

		return $name;
	}

	public function getHookType() {
		// \\do_action\\md5hereofthehook
		$function = explode( '\\', (string) $this->fqsen )[1];

		$type     = 'filter';
		switch ( (string) $function ) {
			case 'do_action':
				$type = 'action';
				break;
			case 'do_action_ref_array':
				$type = 'action_reference';
				break;
			case 'do_action_deprecated':
				$type = 'action_deprecated';
				break;
			case 'apply_filters_ref_array':
				$type = 'filter_reference';
				break;
			case 'apply_filters_deprecated';
				$type = 'filter_deprecated';
				break;
		}

		return $type;
	}

    /**
     * Returns the arguments of this function.
     *
     * @return Argument[]
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * Add an argument to the function.
     */
    public function addArgument(Argument $argument): void
    {
        $this->arguments[] = $argument;
    }

    /**
     * Returns the Fqsen of the element.
     */
    public function getFqsen(): Fqsen
    {
        return $this->fqsen;
    }

    /**
     * Returns the name of the element.
     */
    public function getName(): string
    {
        return $this->fqsen->getName();
    }

    /**
     * Returns the DocBlock of the element if available
     */
    public function getDocBlock(): ?DocBlock
    {
        return $this->docBlock;
    }

    public function getLocation(): Location
    {
        return $this->location;
    }

    public function getEndLocation(): Location
    {
        return $this->endLocation;
    }

    public function getReturnType(): Type
    {
        return $this->returnType;
    }

    public function getHasReturnByReference(): bool
    {
        return $this->hasReturnByReference;
    }
}
