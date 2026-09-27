<?php

declare(strict_types=1);

namespace Vsh\Core\Migrations;

/**
 * Découpe un script SQL en instructions exécutables une à une.
 * Ignore les « ; » situés dans des chaînes ('…', "…", `…`) et les commentaires (--, #, /* … *\/).
 * Limite connue : pas de gestion de DELIMITER (procédures stockées, triggers), non utilisés dans ce projet.
 */
final class SqlSplitter
{
    /**
     * @return string[]
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $quote !== '`' && $next !== '') {
                    $current .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    if ($next === $quote) {
                        $current .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            $isDashComment = $char === '-' && $next === '-'
                && ($i + 2 >= $length || ctype_space($sql[$i + 2]));
            if ($isDashComment || $char === '#') {
                $endOfLine = strpos($sql, "\n", $i);
                $i = $endOfLine === false ? $length : $endOfLine;
                $current .= "\n";
                continue;
            }
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                $current .= ' ';
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                continue;
            }
            if ($char === ';') {
                self::push($statements, $current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        self::push($statements, $current);

        return $statements;
    }

    /**
     * @param string[] $statements
     */
    private static function push(array &$statements, string $statement): void
    {
        $statement = trim($statement);
        if ($statement !== '') {
            $statements[] = $statement;
        }
    }
}
