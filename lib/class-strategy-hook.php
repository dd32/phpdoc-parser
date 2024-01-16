<?php

declare(strict_types=1);

namespace WP_Parser;

use phpDocumentor\Reflection\Php\Factory\AbstractFactory;
use phpDocumentor\Reflection\Php\Factory\ContextStack;

use phpDocumentor\Reflection\DocBlockFactoryInterface;
use phpDocumentor\Reflection\Fqsen;
use phpDocumentor\Reflection\Location;
use phpDocumentor\Reflection\Php\Constant as ConstantElement;
use phpDocumentor\Reflection\Php\File as FileElement;
use phpDocumentor\Reflection\Php\StrategyContainer;
use phpDocumentor\Reflection\Php\ValueEvaluator\ConstantEvaluator;
use PhpParser\ConstExprEvaluationException;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
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

    public function matches(ContextStack $context, object $object): bool
    {
        if (!$object instanceof Expression) {
            return false;
        }

        $expression = $object->expr;
        if (!$expression instanceof FuncCall) {
            return false;
        }

        if (!$expression->name instanceof Name) {
            return false;
        }

        return (string) $expression->name === 'do_action';
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
        assert($expression instanceof FuncCall);

        [$name, $value] = $expression->args;

        //We cannot calculate the name of a variadic consuming define.
        if ($name instanceof VariadicPlaceholder || $value instanceof VariadicPlaceholder) {
            return;
        }

        $file = $context->search(FileElement::class);
        assert($file instanceof FileElement);

        $fqsen = $this->determineFqsen($name, $context);
        if ($fqsen === null) {
            return;
        }

        $constant = new ConstantElement(
            $fqsen,
            $this->createDocBlock($object->getDocComment(), $context->getTypeContext()),
            $this->determineValue($value),
            new Location($object->getLine()),
            new Location($object->getEndLine())
        );


		// $file is instance of phpDocumentor\Reflection\Php\File https://github.com/phpDocumentor/Reflection/blob/770440f9922d1e3d118d234fd6ab72048ddc5b05/src/phpDocumentor/Reflection/Php/File.php
		// Maybe use MetadataContainer ? https://github.com/phpDocumentor/Reflection/blob/d3613a13f521b1db92add4bed58dde541d7e2941/src/phpDocumentor/Reflection/Php/MetadataContainer.php#L23

        // $file->addConstant($constant); // TODO: This is annoying, now I need to figure out how to attach this to the file.
		$file->actions ??= [];
		$file->actions[] = $constant;

		// Maybe do a dirty and use a global, because we all love globals!
    }

    private function determineValue(?Arg $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->valueConverter->prettyPrintExpr($value->value);
    }

    private function determineFqsen(Arg $name, ContextStack $context): ?Fqsen
    {
        return $this->fqsenFromExpression($name->value, $context);
    }

    private function fqsenFromExpression(Expr $nameString, ContextStack $context): ?Fqsen
    {
        try {
            return $this->fqsenFromString($this->constantEvaluator->evaluate($nameString, $context));
        } catch (ConstExprEvaluationException $e) {
            //Ignore any errors as we cannot evaluate all expressions
            return null;
        }
    }

    private function fqsenFromString(string $nameString): Fqsen
    {
        if (str_starts_with($nameString, '\\') === false) {
            return new Fqsen(sprintf('\\%s', $nameString));
        }

        return new Fqsen(sprintf('%s', $nameString));
    }
}