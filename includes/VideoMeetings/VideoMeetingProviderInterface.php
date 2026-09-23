<?php
namespace Wpcb\VideoMeetings;

interface VideoMeetingProviderInterface {
    public function code(): string;
    public function capabilities(): array;
    public function create(array $booking, object $connection): array;
    public function update(array $meeting, array $booking, object $connection): array;
    public function delete(array $meeting, object $connection): array;
}
