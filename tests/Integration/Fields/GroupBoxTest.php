<?php

namespace FabricatorForms\Tests\Integration\Fields;

use FabricatorForms\Fields\GroupField;
use FabricatorForms\Tests\Integration\AjaxTestCase;

/**
 * A group's "Show as box": its fields in the direct debit mandate's box, headed by its label, on the page and in the
 * mail. Off, the group is as it always was. The PDF, nested boxes included, is checked in SealRoundTripTest.
 */
final class GroupBoxTest extends AjaxTestCase
{
    private static function group(array $extra = []): array
    {
        return $extra + ['id' => 'member', 'type' => 'group', 'label' => 'Membership', 'children' => [
            ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['id' => 'city', 'type' => 'text', 'label' => 'City'],
        ]];
    }

    public function testABoxedGroupsFieldsSitInABoxTheLabelHeadsInTheMail(): void
    {
        $form = $this->createForm([self::group(['boxed' => true]), ['id' => 'after', 'type' => 'text', 'label' => 'After']], [self::notification()]);

        $r = $this->submit($form, ['name' => 'Ada Lovelace', 'city' => 'London', 'after' => 'Outside']);

        self::assertTrue($r['success'], (string) wp_json_encode($r));
        $body  = (string) self::sentMail()[0]->body;
        $html  = substr($body, (int) strpos($body, 'text/html'));
        $order = array_map(static fn(string $needle): int|false => strpos($html, $needle), [
            '<table role="presentation"', '<strong>Membership</strong>', 'Ada Lovelace', 'London', '</td></tr></table>', 'Outside',
        ]);
        self::assertNotContains(false, $order, $html);
        $sorted = $order;
        sort($sorted);
        self::assertSame($sorted, $order, 'title, the group\'s fields, the box\'s end, then what follows');
        self::assertStringNotContainsString('End of', $html);
    }

    public function testWithoutTheSwitchTheGroupIsAsBefore(): void
    {
        $form = $this->createForm([self::group()], [self::notification()]);

        $r = $this->submit($form, ['name' => 'Ada Lovelace', 'city' => 'London']);

        self::assertTrue($r['success'], (string) wp_json_encode($r));
        $body = (string) self::sentMail()[0]->body;
        self::assertStringContainsString('Ada Lovelace', $body);
        self::assertStringNotContainsString('<table', $body);
        self::assertStringNotContainsString('Membership', $body, 'no box, no title');
    }

    public function testOnThePageTheBoxIsTheMandatesHeadedByTheLabelUnlessItIsHidden(): void
    {
        $group = new GroupField();
        self::assertFalse($group->getDefaultConfig()['boxed'], 'off unless switched on');

        $plain = $group->openTagHtml(self::group(), 'member');
        self::assertStringNotContainsString('fabricator-field-group--boxed', $plain);
        self::assertStringNotContainsString('Membership', $plain);

        $boxed = $group->openTagHtml(self::group(['boxed' => true]), 'member');
        self::assertStringContainsString('fabricator-field-group--boxed', $boxed);
        self::assertStringContainsString('<h3 class="fabricator-group-title">Membership</h3>', $boxed);

        $hidden = $group->openTagHtml(self::group(['boxed' => true, 'hide_label' => true]), 'member');
        self::assertStringContainsString('fabricator-field-group--boxed', $hidden);
        self::assertStringNotContainsString('Membership', $hidden);
    }

    public function testABoxedGroupWhoseFieldsAreAllHiddenRecordsNoBox(): void
    {
        $mapped = (new GroupField())->mapNormalized('member', 'Membership', ['name' => 'x'], self::group(['boxed' => true]), [
            'skip_ids' => ['name' => true, 'city' => true],
        ]);
        self::assertSame([], $mapped);
    }
}
