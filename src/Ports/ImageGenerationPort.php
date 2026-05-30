<?php

namespace Andmarruda\LaravelAgents\Ports;

use Andmarruda\LaravelAgents\Data\ImageGenerationRequest;
use Andmarruda\LaravelAgents\Data\ImageGenerationResponse;

interface ImageGenerationPort extends CapabilityPort
{
    public function generate(ImageGenerationRequest $request): ImageGenerationResponse;
}
