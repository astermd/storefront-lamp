<?php

declare(strict_types=1);

/* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */

namespace AsterMD\Storefront\Forms;

/**
 * The steps drawn above a questionnaire, derived from the questionnaire.
 *
 * They used to be four constants — Eligibility, Contact, Medical, Verify &
 * Review — which described the funnel rather than the form. Above a five-page
 * intake that showed four steps, three of them permanently inert, and none of
 * them naming a question the visitor was being asked. A stepper that does not
 * move with the form is worse than none: `[25.10]` asks for progress to be
 * visible, and a fixed row of labels reports progress that is not happening.
 *
 * Two sources, in priority order, because the EMR offers both:
 *
 * 1. An authored `form-progress` field. Its `progressStepItems` name a page
 *    and the text for it, which is the form author saying what the steps are
 *    — so it outranks anything derived, including a derivation that would
 *    have produced better words.
 * 2. Otherwise one step per page. The page's own title, unless that title is
 *    the builder's `Page N` default, which names nothing; then the page's
 *    first heading field, which is what the visitor actually reads at the top
 *    of it. Failing both, the position.
 *
 * No icons. The EMR names none, and inventing one per step would attach a
 * meaning to pages that do not have it — {@see theme/templates/partials/stepper.twig}
 * draws the step's number when a step carries no icon, which is what a
 * numbered page actually is.
 */
final class ProgressSteps
{
    /** Titles the form builder generates when the author never set one. They name nothing, so they are not used. */
    private const string PLACEHOLDER_TITLE = '/^page\s*\d+$/i';

    /**
     * @return list<array{label: string, icon: null}>
     */
    public static function from(Definition $definition): array
    {
        $authored = self::authored($definition);

        return $authored !== [] ? $authored : self::fromPages($definition);
    }

    /**
     * The steps an author declared on a `form-progress` field.
     *
     * Ordered by the page each item names rather than by the order the items
     * appear in, because the two are not the same thing: the builder appends
     * a new item at the end whatever page it is given. Items with no usable
     * text are dropped, and a field whose items are all unusable falls through
     * to the pages — an authored-but-empty progress field is not an
     * instruction to draw nothing.
     *
     * @return list<array{label: string, icon: null}>
     */
    private static function authored(Definition $definition): array
    {
        foreach ($definition->fields() as $field) {
            if ($field->type !== 'form-progress') {
                continue;
            }

            $items = $field->properties['progressStepItems'] ?? null;
            if (!is_array($items)) {
                continue;
            }

            $ordered = [];
            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $text = trim((string) ($item['text'] ?? ''));
                if ($text === '') {
                    continue;
                }

                // `page` is one-based where it is given at all; the written
                // position is the tiebreak so two items on one page keep the
                // order they were authored in.
                $ordered[] = [(int) ($item['page'] ?? PHP_INT_MAX), (int) $index, $text];
            }

            if ($ordered === []) {
                continue;
            }

            usort($ordered, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

            return array_map(static fn (array $row): array => ['label' => $row[2], 'icon' => null], $ordered);
        }

        return [];
    }

    /** @return list<array{label: string, icon: null}> */
    private static function fromPages(Definition $definition): array
    {
        $steps = [];
        foreach ($definition->pages() as $index => $page) {
            $steps[] = ['label' => self::labelFor($page, $index), 'icon' => null];
        }

        return $steps;
    }

    private static function labelFor(Page $page, int $index): string
    {
        $title = trim((string) ($page->title ?? ''));
        if ($title !== '' && preg_match(self::PLACEHOLDER_TITLE, $title) !== 1) {
            return $title;
        }

        foreach ($page->fields as $field) {
            if ($field->type === 'heading' && trim($field->label) !== '') {
                return trim($field->label);
            }
        }

        return 'Step ' . ($index + 1);
    }
}
