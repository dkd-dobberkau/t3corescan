<?php

declare(strict_types=1);

namespace T3x\T3Corescan\Tests\Fixtures\Weak;

/**
 * Produces weak hits and nothing else.
 *
 * MethodCallMatcher cannot know the receiver's type, so it reports every call to
 * a known method name as `weak` — a name collision is as likely as a real
 * finding. The receiver here is deliberately untyped and no class name appears
 * in this file, so no matcher can produce a strong hit.
 *
 * `confirmMsg()` with four arguments is a rule in both the v13.4 and the v14.3
 * ruleset, so this fixture behaves the same on either line.
 */
final class WeakHitOnly
{
    public function run(mixed $subject): void
    {
        $subject->confirmMsg('table', 'record', 'reference', 'message');
    }
}
