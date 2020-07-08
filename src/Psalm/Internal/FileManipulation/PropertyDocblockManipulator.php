<?php
namespace Psalm\Internal\FileManipulation;

use PhpParser;
use function array_shift;
use function count;
use function ltrim;
use PhpParser\Node\Stmt\Property;
use function preg_match;
use Psalm\DocComment;
use Psalm\FileManipulation;
use Psalm\Internal\Analyzer\CommentAnalyzer;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use function str_replace;
use function str_split;
use function strlen;
use function strpos;
use function strrpos;
use function substr;

/**
 * @internal
 */
class PropertyDocblockManipulator
{
    /** @var array<string, array<string, self>> */
    private static $manipulators = [];

    /**
     * Manipulators ordered by line number
     *
     * @var array<string, array<int, self>>
     */
    private static $ordered_manipulators = [];

    /** @var Property */
    private $stmt;

    /** @var int */
    private $docblock_start;

    /** @var int */
    private $docblock_end;

    /** @var null|int */
    private $typehint_start;

    /** @var int */
    private $typehint_area_start;

    /** @var null|int */
    private $typehint_end;

    /** @var null|string */
    private $new_php_type;

    /** @var bool */
    private $type_is_php_compatible = false;

    /** @var null|string */
    private $new_phpdoc_type;

    /** @var null|string */
    private $new_psalm_type;

    /** @var string */
    private $indentation;

    /** @var string|null */
    private $type_description;

    public static function getForProperty(
        ProjectAnalyzer $project_analyzer,
        string $file_path,
        string $property_id,
        Property $stmt
    ) : self {
        if (isset(self::$manipulators[$file_path][$property_id])) {
            return self::$manipulators[$file_path][$property_id];
        }

        $manipulator
            = self::$manipulators[$file_path][$property_id]
            = self::$ordered_manipulators[$file_path][$stmt->getLine()]
            = new self($project_analyzer, $stmt, $file_path);

        return $manipulator;
    }

    private function __construct(
        ProjectAnalyzer $project_analyzer,
        Property $stmt,
        string $file_path
    ) {
        $this->stmt = $stmt;
        $docblock = $stmt->getDocComment();
        $this->docblock_start = $docblock ? $docblock->getFilePos() : (int)$stmt->getAttribute('startFilePos');
        $this->docblock_end = (int)$stmt->getAttribute('startFilePos');

        $codebase = $project_analyzer->getCodebase();

        $file_contents = $codebase->getFileContents($file_path);

        if (count($stmt->props) > 1) {
            throw new \UnexpectedValueException('Cannot replace multiple properties');
        }

        $prop = $stmt->props[0];

        if ($stmt->type) {
            $this->typehint_start = (int)$stmt->type->getAttribute('startFilePos');
            $this->typehint_end = (int)$stmt->type->getAttribute('endFilePos');
        }

        $this->typehint_area_start = (int)$prop->getAttribute('startFilePos') - 1;

        $preceding_newline_pos = strrpos($file_contents, "\n", $this->docblock_end - strlen($file_contents));

        if ($preceding_newline_pos === false) {
            $this->indentation = '';

            return;
        }

        $first_line = substr($file_contents, $preceding_newline_pos + 1, $this->docblock_end - $preceding_newline_pos);

        $this->indentation = str_replace(ltrim($first_line), '', $first_line);
    }

    public function setType(
        ?string $php_type,
        string $new_type,
        string $phpdoc_type,
        bool $is_php_compatible,
        ?string $description = null
    ) : void {
        $new_type = str_replace(['<mixed, mixed>', '<array-key, mixed>', '<empty, empty>'], '', $new_type);

        $this->new_php_type = $php_type;
        $this->new_phpdoc_type = $phpdoc_type;
        $this->new_psalm_type = $new_type;
        $this->type_is_php_compatible = $is_php_compatible;
        $this->type_description = $description;
    }

    /**
     * Gets a new docblock given the existing docblock, if one exists, and the updated return types
     * and/or parameters
     *
     * @return string
     */
    private function getDocblock()
    {
        $docblock = $this->stmt->getDocComment();

        if ($docblock) {
            $parsed_docblock = DocComment::parsePreservingLength($docblock);
        } else {
            $parsed_docblock = new \Psalm\Internal\Scanner\ParsedDocblock('', []);
        }

        $modified_docblock = false;

        $old_phpdoc_type = null;
        if (isset($parsed_docblock->tags['var'])) {
            $old_phpdoc_type = array_shift($parsed_docblock->tags['var']);
        }

        if ($this->new_phpdoc_type
            && $this->new_phpdoc_type !== $old_phpdoc_type
        ) {
            $modified_docblock = true;
            $parsed_docblock->tags['var'] = [
                $this->new_phpdoc_type
                    . ($this->type_description ? (' ' . $this->type_description) : ''),
            ];
        }

        $old_psalm_type = null;
        if (isset($parsed_docblock->tags['psalm-var'])) {
            $old_psalm_type = array_shift($parsed_docblock->tags['psalm-var']);
        }

        if ($this->new_psalm_type
            && $this->new_phpdoc_type !== $this->new_psalm_type
            && $this->new_psalm_type !== $old_psalm_type
        ) {
            $modified_docblock = true;
            $parsed_docblock->tags['psalm-var'] = [$this->new_psalm_type];
        }

        if (!$parsed_docblock->tags && !$parsed_docblock->description) {
            return '';
        }

        if (!$modified_docblock) {
            return (string)$docblock . "\n" . $this->indentation;
        }

        return $parsed_docblock->render($this->indentation);
    }

    /**
     * @param  string $file_path
     *
     * @return array<int, FileManipulation>
     */
    public static function getManipulationsForFile($file_path)
    {
        if (!isset(self::$manipulators[$file_path])) {
            return [];
        }

        $file_manipulations = [];

        foreach (self::$ordered_manipulators[$file_path] as $manipulator) {
            if ($manipulator->new_php_type) {
                if ($manipulator->typehint_start && $manipulator->typehint_end) {
                    $file_manipulations[$manipulator->typehint_start] = new FileManipulation(
                        $manipulator->typehint_start,
                        $manipulator->typehint_end,
                        $manipulator->new_php_type
                    );
                } else {
                    $file_manipulations[$manipulator->typehint_area_start] = new FileManipulation(
                        $manipulator->typehint_area_start,
                        $manipulator->typehint_area_start,
                        ' ' . $manipulator->new_php_type
                    );
                }
            } elseif ($manipulator->new_php_type === ''
                && $manipulator->new_phpdoc_type
                && $manipulator->typehint_start
                && $manipulator->typehint_end
            ) {
                $file_manipulations[$manipulator->typehint_start] = new FileManipulation(
                    $manipulator->typehint_start,
                    $manipulator->typehint_end,
                    ''
                );
            }

            if (!$manipulator->new_php_type
                || !$manipulator->type_is_php_compatible
                || $manipulator->docblock_start !== $manipulator->docblock_end
            ) {
                $file_manipulations[$manipulator->docblock_start] = new FileManipulation(
                    $manipulator->docblock_start,
                    $manipulator->docblock_end,
                    $manipulator->getDocblock()
                );
            }
        }

        return $file_manipulations;
    }
}
