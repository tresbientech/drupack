<?php

declare(strict_types=1);

namespace Drupal\fixture\Controller;

// Renders the fixture pack's icon, which the svg extractor reads from disk.
class Icons {

  public function page(): array {
    return ['#type' => 'icon', '#pack_id' => 'fixture', '#icon_id' => 'star'];
  }

}
