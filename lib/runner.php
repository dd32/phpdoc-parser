<?php

namespace WP_Parser;

/*use phpDocumentor\Reflection\BaseReflector;
use phpDocumentor\Reflection\ClassReflector\MethodReflector;
use phpDocumentor\Reflection\ClassReflector\PropertyReflector;
use phpDocumentor\Reflection\FunctionReflector;
use phpDocumentor\Reflection\FunctionReflector\ArgumentReflector;
use phpDocumentor\Reflection\ReflectionAbstract;*/

use phpDocumentor\Reflection\Php\ProjectFactory;
use phpDocumentor\Reflection\File\LocalFile;

use phpDocumentor\Reflection\DocBlockFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;


/**
 * @param string $directory
 *
 * @return array|\WP_Error
 */
function get_wp_files( $directory ) {
	$iterableFiles = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $directory )
	);
	$files         = array();

	try {
		foreach ( $iterableFiles as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$files[] = $file->getPathname();
		}
	} catch ( \UnexpectedValueException $exc ) {
		return new \WP_Error(
			'unexpected_value_exception',
			sprintf( 'Directory [%s] contained a directory we can not recurse into', $directory )
		);
	}

	return $files;
}

/**
 * @param array  $files
 * @param string $root
 *
 * @return array
 */
function parse_files( $files, $root ) {
	$output = array();

	$file_objects   = [];
	$project_factory = ProjectFactory::createInstance();
	
	// https://github.com/phpDocumentor/Reflection/blob/770440f9922d1e3d118d234fd6ab72048ddc5b05/src/phpDocumentor/Reflection/Php/ProjectFactory.php#L49
	require_once __DIR__ . '/class-strategy-hook.php';
	require_once __DIR__ . '/class-strategy-uses.php';

	$project_factory->addStrategy(
		new Strategy_Hook(
			DocBlockFactory::createInstance(),
			new PrettyPrinter()
		)
	);

	$project_factory->addStrategy(
		new Strategy_Uses(
			DocBlockFactory::createInstance(),
			new PrettyPrinter()
		)
	);

	foreach ( $files as $filename ) {
		$file_objects[] = new LocalFile( $filename );
	}
	$project        = $project_factory->create( 'phpdoc-parser', $file_objects );

	foreach ( $project->getFiles() as $file ) {
		// TODO proper exporter
		$out = array(
			'file' => export_docblock( $file ),
			'path' => str_replace( DIRECTORY_SEPARATOR, '/', $file->getName() ),
			'root' => $root,
		);

		if ( ! empty( $file->uses ) ) {
			$out['uses'] = export_uses( $file->uses );
		}

		foreach ( $file->getIncludes() as $include ) {
			$out['includes'][] = array(
				'name' => $include->getName(),
				'line' => $include->getLocation()->getLineNumber(),
				'type' => $include->getType(),
			);
		}

		foreach ( $file->getConstants() as $constant ) {
			$out['constants'][] = array(
				'name'  => $constant->getName(),
				'line'  => $constant->getLocation()->getLineNumber(),
				'value' => $constant->getValue(),
			);
		}

		if ( ! empty( $file->uses['hooks'] ) ) {
			$out['hooks'] = export_hooks( $file->uses['hooks'] );
		}

		foreach ( $file->getFunctions() as $function ) {
			$func = array(
				'name'      => $function->getName(),
				'namespace' => ltrim( substr( (string) $function->getFqsen(), 0, -1 * strlen( $function->getName() ) - 3 ), '\\' ),
				'aliases'   => [], // Unknown $function->getNamespaceAliases(),
				'line'      => $function->getLocation()->getLineNumber(),
				'end_line'  => $function->getEndLocation()->getLineNumber(),
				'arguments' => export_arguments( $function->getArguments() ),
				'doc'       => export_docblock( $function ),
				'hooks'     => array(),
			);

			// TODO: Hmmmmm this will be provided by our file class too I think..
			if ( ! empty( $function->uses ) ) {
				$func['uses'] = export_uses( $function->uses );

				if ( ! empty( $function->uses['hooks'] ) ) {
					$func['hooks'] = export_hooks( $function->uses['hooks'] );
				}
			}

			$out['functions'][] = $func;
		}

		foreach ( $file->getClasses() as $class ) {
			$class_data = array(
				'name'       => $class->getName(),
				'namespace'  => ltrim( substr( (string) $class->getFqsen(), 0, -1 * strlen( $class->getName() ) - 3 ), '\\' ),
				'line'       => $class->getLocation()->getLineNumber(),
				'end_line'   => $class->getEndLocation()->getLineNumber(),
				'final'      => $class->isFinal(),
				'abstract'   => $class->isAbstract(),
				'extends'    => (string) $class->getParent(),
				'implements' => $class->getInterfaces(),
				'properties' => export_properties( $class->getProperties() ),
				'methods'    => export_methods( $class->getMethods() ),
				'doc'        => export_docblock( $class ),
			);

			$out['classes'][] = $class_data;
		}

		$output[] = $out;
	}

	return $output;
}

/**
 * Fixes newline handling in parsed text.
 *
 * DocBlock lines, particularly for descriptions, generally adhere to a given character width. For sentences and
 * paragraphs that exceed that width, what is intended as a manual soft wrap (via line break) is used to ensure
 * on-screen/in-file legibility of that text. These line breaks are retained by phpDocumentor. However, consumers
 * of this parsed data may believe the line breaks to be intentional and may display the text as such.
 *
 * This function fixes text by merging consecutive lines of text into a single line. A special exception is made
 * for text appearing in `<code>` and `<pre>` tags, as newlines appearing in those tags are always intentional.
 *
 * @param string $text
 *
 * @return string
 */
function fix_newlines( $text ) {
	// Non-naturally occurring string to use as temporary replacement.
	$replacement_string = '{{{{{}}}}}';

	// Replace newline characters within 'code' and 'pre' tags with replacement string.
	$text = preg_replace_callback(
		"/(<pre><code[^>]*>)(.+)(?=<\/code><\/pre>)/sU",
		function ( $matches ) use ( $replacement_string ) {
			return preg_replace( '/[\n\r]/', $replacement_string, $matches[1] . $matches[2] );
		},
		$text
	);

	// Insert a newline when \n follows `.`.
	$text = preg_replace(
		"/\.[\n\r]+(?!\s*[\n\r])/m",
		'.<br>',
		$text
	);

	// Insert a new line when \n is followed by what appears to be a list.
	$text = preg_replace(
		"/[\n\r]+(\s+[*-] )(?!\s*[\n\r])/m",
		'<br>$1',
		$text
	);

	// Merge consecutive non-blank lines together by replacing the newlines with a space.
	$text = preg_replace(
		"/[\n\r](?!\s*[\n\r])/m",
		' ',
		$text
	);

	// Restore newline characters into code blocks.
	$text = str_replace( $replacement_string, "\n", $text );

	return $text;
}

/**
 * @param BaseReflector|ReflectionAbstract $element
 *
 * @return array
 */
function export_docblock( $element ) {
	$docblock = $element->getDocBlock();
	if ( ! $docblock ) {
		return array(
			'description'      => '',
			'long_description' => '',
			'tags'             => array(),
		);
	}

	$output = array(
		'description'      => preg_replace( '/[\n\r]+/', ' ', $docblock->getSummary() ),
		'long_description' => fix_newlines( (string) $docblock->getDescription()->render() ),
		'tags'             => array(),
	);

	foreach ( $docblock->getTags() as $tag ) {
		$tag_data = array(
			'name'    => $tag->getName(),
			'content' => preg_replace( '/[\n\r]+/', ' ', format_description( $tag->getDescription() ) ),
		);
		if ( method_exists( $tag, 'getType' ) ) {
			$tag_data['types'] = array(
				(string) $tag->getType()
			);
		}
		if ( method_exists( $tag, 'getLink' ) ) {
			$tag_data['link'] = $tag->getLink();
		}
		if ( method_exists( $tag, 'getVariableName' ) ) {
			$tag_data['variable'] = '$' . $tag->getVariableName();
		}
		if ( method_exists( $tag, 'getReference' ) ) {
			$tag_data['refers'] = $tag->getReference();
		}
		if ( method_exists( $tag, 'getVersion' ) ) {
			// Version string.
			$version = $tag->getVersion();
			if ( ! empty( $version ) ) {
				$tag_data['content'] = $version;
			}
			// Description string.
			if ( method_exists( $tag, 'getDescription' ) ) {
				$description = preg_replace( '/[\n\r]+/', ' ', format_description( $tag->getDescription() ) );
				if ( ! empty( $description ) ) {
					$tag_data['description'] = $description;
				}
			}
		}
		$output['tags'][] = $tag_data;
	}

	return $output;
}

/**
 * @param Hook_Reflector[] $hooks
 *
 * @return array
 */
function export_hooks( array $hooks ) {
	$out = array();

	foreach ( $hooks as $hook ) {
		$out[] = array(
			'name'      => $hook->getHookName(),
			'line'      => $hook->getLocation()->getLineNumber(),
			'end_line'  => $hook->getEndLocation()->getLineNumber(),
			'type'      => $hook->getHookType(),
			'arguments' => wp_list_pluck( export_arguments( $hook->getArguments() ), 'name' ),
			'doc'       => export_docblock( $hook ),
		);
	}

	return $out;
}

/**
 * @param ArgumentReflector[] $arguments
 *
 * @return array
 */
function export_arguments( array $arguments ) {
	$output = array();

	foreach ( $arguments as $argument ) {
		$output[] = array(
			'name'    => $argument->getName(),
			'default' => $argument->getDefault(),
			'type'    => $argument->getType(), // TODO What's this expected to be.
		);
	}

	return $output;
}

/**
 * @param PropertyReflector[] $properties
 *
 * @return array
 */
function export_properties( array $properties ) {
	$out = array();

	foreach ( $properties as $property ) {
		$out[] = array(
			'name'        => '$' . $property->getName(),
			'line'        => $property->getLocation()->getLineNumber(),
			'end_line'    => $property->getEndLocation()->getLineNumber(),
			'default'     => $property->getDefault(),
//			'final' => $property->isFinal(),
			'static'      => $property->isStatic(),
			'visibility'  => (string) $property->getVisibility(),
			'doc'         => export_docblock( $property ),
		);
	}

	return $out;
}

/**
 * @param MethodReflector[] $methods
 *
 * @return array
 */
function export_methods( array $methods ) {
	$output = array();

	foreach ( $methods as $method ) {

		$method_data = array(
			'name'       => $method->getName(),
			'namespace'  => (string) $method->getFqsen(),
			'aliases'    => [],//$method->getNamespaceAliases(),
			'line'       => $method->getLocation()->getLineNumber(),
			'end_line'   => $method->getEndLocation()->getLineNumber(),
			'final'      => $method->isFinal(),
			'abstract'   => $method->isAbstract(),
			'static'     => $method->isStatic(),
			'visibility' => (string) $method->getVisibility(),
			'arguments'  => export_arguments( $method->getArguments() ),
			'doc'        => export_docblock( $method ),
		);

		if ( ! empty( $method->uses ) ) {
			$method_data['uses'] = export_uses( $method->uses );

			if ( ! empty( $method->uses['hooks'] ) ) {
				$method_data['hooks'] = export_hooks( $method->uses['hooks'] );
			}
		}

		$output[] = $method_data;
	}

	return $output;
}

/**
 * Export the list of elements used by a file or structure.
 *
 * @param array $uses {
 *        @type Function_Call_Reflector[] $functions The functions called.
 * }
 *
 * @return array
 */
function export_uses( array $uses ) {
	$out = array();

	// Ignore hooks here, they are exported separately.
	unset( $uses['hooks'] );

	foreach ( $uses as $type => $used_elements ) {

		/** @var MethodReflector|FunctionReflector $element */
		foreach ( $used_elements as $element ) {

			switch ( $type ) {
				case 'methods':
					[ $class, $element ] = $element;
					$name                = (string) $element->name;
//var_dump( $element );
					$out[ $type ][] = array(
						'name'     => $name,
						'class'    => $class,
						'static'   => ( 'Expr_StaticCall' === $element->getType() ), // TODO?
						'line'     => $element->getStartLine(),
						'end_line' => $element->getEndLine(),
					);
					break;

				default:
				case 'functions':
					$name = (string) $element->name;

					$out[ $type ][] = array(
						'name'     => $name,
						'line'     => $element->getStartLine(),
						'end_line' => $element->getEndLine(),
					);

					if ( '_deprecated_file' === $name
						|| '_deprecated_function' === $name
						|| '_deprecated_argument' === $name
						|| '_deprecated_hook' === $name
					) {
						// TODO
						$arguments = $element->getNode()->args;

						$out[ $type ][0]['deprecation_version'] = $arguments[1]->value->value;
					}

					break;
			}
		}
	}

	return $out;
}

/**
 * Format the given description with Markdown.
 *
 * @param string $description Description.
 * @return string Description as Markdown if the Parsedown class exists, otherwise return
 *                the given description text.
 */
function format_description( $description ) {
	if ( class_exists( 'Parsedown' ) ) {
		$parsedown   = \Parsedown::instance();
		$description = $parsedown->line( $description );
	}

	$description = fix_newlines( $description );

	return $description;
}
