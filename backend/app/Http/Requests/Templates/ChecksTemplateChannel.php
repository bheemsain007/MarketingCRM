<?php

namespace App\Http\Requests\Templates;

use App\Enums\Channel;
use Illuminate\Contracts\Validation\Validator;

/**
 * The two channel rules the create and update paths share (FR-COMM-02).
 *
 * Shared rather than duplicated because they must not drift: a rule enforced on
 * create and forgotten on update is a rule an operator reaches by saving twice.
 */
trait ChecksTemplateChannel
{
    /**
     * @param  string|null  $channel  the EFFECTIVE channel - on update that is
     *                                the submitted one, or the stored one when
     *                                the request does not change it
     * @param  bool  $hasSubject  whether this request supplies a non-empty subject
     */
    protected function applyTemplateChannelRules(Validator $validator, ?string $channel, bool $hasSubject): void
    {
        $resolved = $channel === null ? null : Channel::tryFrom($channel);

        // An unknown channel is already a failure from the enum rule; adding a
        // second message about it would just be noise.
        if ($resolved === null) {
            return;
        }

        /*
         * `call` and `ai_call` are Channel cases but not MESSAGE channels: they
         * place a call rather than send anything, and no code path reads a
         * template for them - an AI call's script belongs to Vaaad, not to this
         * table. Accepting one would store a record that looks usable, appears
         * in template pickers, and can never be sent.
         */
        if ($resolved->isVoiceCall()) {
            $validator->errors()->add('channel', sprintf(
                'Templates are for message channels only - %s places a call rather than sending a message.',
                $resolved->label(),
            ));
        }

        // StoreMessageRequest's rule, word for word. A template that may carry a
        // subject where a message may not would let an operator author a
        // headline the send path silently discards.
        if ($resolved !== Channel::Email && $hasSubject) {
            $validator->errors()->add('subject', 'Only email templates carry a subject.');
        }
    }
}
