<?php

declare(strict_types=1);

namespace Semitexa\Frontend\Http\Response;

use Semitexa\Core\Http\Response\GenericResponse;
use Semitexa\Frontend\View\TwigFactory;

class HtmlResponse extends GenericResponse
{
    public function __construct()
    {
        parent::__construct('', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function renderTemplate(string $template, array $context = []): self
    {
        $html = TwigFactory::get()->render($template, $context);
        $this->setContent($html);
        return $this;
    }

    public function renderString(string $templateSource, array $context = []): self
    {
        $twig = TwigFactory::get();
        $template = $twig->createTemplate($templateSource);
        $html = $template->render($context);
        $this->setContent($html);
        return $this;
    }
}


