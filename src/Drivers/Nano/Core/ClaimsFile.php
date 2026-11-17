<?php

namespace Shetabit\Multipay\Drivers\Nano\Core;

use Shetabit\Multipay\Exceptions\InvalidPaymentException;

/**
 * The durable claim ledger for Nano payments: one send block credits one
 * invoice, once, and the record survives a restart.
 *
 * A confirmed Nano send stays valid forever and its hash is public, so replay
 * protection cannot live in memory: a process restart that forgot its claims
 * would let every old block be presented again. Each claim is appended and
 * fsynced to a file under the operator's data directory and reloaded on the
 * next construction, so the "block hash -> invoice" record outlives the PHP
 * worker. Pure: no dependency and no network, so it unit-tests on its own.
 *
 * File format: one `HASH\tORDER\n` line per claim; the hash is upper-case so a
 * re-read in another case is the same block.
 */
final class ClaimsFile
{
    /** @var array<string, string> block hash (upper) => order id */
    private array $byHash = [];

    /** @var array<string, string> order id => block hash (upper) */
    private array $byOrder = [];

    public function __construct(private readonly string $path)
    {
        $this->load();
    }

    /**
     * Record that $blockHash settles $orderId.
     *
     * @return bool true when this is the first time the block is credited (the
     *              caller should release the invoice now); false when the same
     *              block was already credited to the same order (idempotent -
     *              a retried callback must not credit twice)
     *
     * @throws InvalidPaymentException when the block was already spent on another
     *                           order, or the order was already paid by another
     *                           block - neither is ever credited
     */
    public function claim(string $blockHash, string $orderId): bool
    {
        $hash = strtoupper(trim($blockHash));
        if (preg_match('/^[0-9A-F]{64}$/', $hash) !== 1) {
            throw new InvalidPaymentException('A Nano block hash is 64 hex characters.');
        }

        if ($orderId === '' || preg_match('/[\t\r\n]/', $orderId) === 1) {
            throw new InvalidPaymentException('An order id for a Nano claim is one line of text.');
        }

        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new InvalidPaymentException('The Nano claims directory could not be created.');
        }

        $handle = fopen($this->path, 'c+b');
        if ($handle === false) {
            throw new InvalidPaymentException('The Nano claims file could not be opened.');
        }

        // The check and the write happen under one exclusive lock, so two requests that present the same block at
        // the same time cannot both be accepted.
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new InvalidPaymentException('The Nano claims file could not be locked.');
            }

            $this->read($handle);

            if (isset($this->byHash[$hash])) {
                if ($this->byHash[$hash] === $orderId) {
                    return false;
                }

                throw new InvalidPaymentException('This Nano block was already used to pay another invoice.');
            }

            if (isset($this->byOrder[$orderId]) && $this->byOrder[$orderId] !== $hash) {
                throw new InvalidPaymentException('This invoice was already paid by another Nano block.');
            }

            fseek($handle, 0, SEEK_END);
            if (fwrite($handle, $hash."\t".$orderId."\n") === false || !fflush($handle)) {
                throw new InvalidPaymentException('The Nano claim could not be written.');
            }
            // The claim is on disk before the receipt is handed out, so a crash cannot forget it.
            fsync($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->byHash[$hash] = $orderId;
        $this->byOrder[$orderId] = $hash;

        return true;
    }

    public function isClaimed(string $blockHash): bool
    {
        return isset($this->byHash[strtoupper(trim($blockHash))]);
    }

    private function load(): void
    {
        $this->byHash = [];
        $this->byOrder = [];

        if (!is_file($this->path)) {
            return;
        }

        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new InvalidPaymentException('The Nano claims file could not be read.');
        }

        try {
            flock($handle, LOCK_SH);
            $this->read($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     */
    private function read($handle): void
    {
        $this->byHash = [];
        $this->byOrder = [];

        rewind($handle);
        while (($line = fgets($handle)) !== false) {
            $parts = explode("\t", rtrim($line, "\r\n"), 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$hash, $order] = $parts;
            $this->byHash[$hash] = $order;
            $this->byOrder[$order] = $hash;
        }
    }
}
