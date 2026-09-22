<?php
namespace MonkeyBot\Channels;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * One adapter per channel (ADR-002, brief §10).
 *
 * Contract (enforced by tests/channels):
 * - sendText()/sendMedia() never throw. A capability the adapter does not
 *   implement returns SendResult::unsupported(), never a fake success.
 * - normalizeInbound*() only parse: no DB, no HTTP, no logging, no exceptions.
 *   Anything that is not an inbound customer message (echoes, receipts,
 *   malformed or foreign payloads) yields NULL / an empty list.
 */
interface ChannelAdapterInterface
{
    /** fb | ig | wa_openwa | wa_cloud | tg | web | tiktok */
    public function key(): string;

    /** @return string[] Capability::* values this adapter implements */
    public function capabilities(): array;

    public function supports(string $capability): bool;

    public function sendText(OutboundMessage $message): SendResult;

    public function sendMedia(OutboundMessage $message): SendResult;

    /**
     * First inbound customer message in $raw, or NULL.
     *
     * @param array $raw     decoded webhook body (or the web widget's POST fields)
     * @param array $context caller-known facts: 'tenant_id' (user_id), 'account_id'
     */
    public function normalizeInbound(array $raw, array $context = array()): ?InboundMessage;

    /**
     * Every inbound customer message in $raw (Meta and WA Cloud batch several
     * events into one delivery).
     *
     * @return InboundMessage[]
     */
    public function normalizeInboundBatch(array $raw, array $context = array()): array;
}
