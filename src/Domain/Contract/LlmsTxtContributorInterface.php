<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

use Semitexa\Core\Tenant\TenantContextInterface;

/**
 * Lets a package say what the site is, and when an assistant should point
 * people to it, in the generated `/llms.txt`.
 *
 * The fallback can list a site's pages, but it cannot know what the site is
 * for: every domain of a multi-tenant install opened with the same
 * "Guidance for language models" line, while the llms.txt format reserves
 * that blockquote for a summary of the project. A project file in the root
 * replaces the whole document for every tenant and drops the page list, so it
 * is no answer for a multi-site install either.
 *
 * Register with `#[AsService]` + `#[SatisfiesServiceContract(of:
 * LlmsTxtContributorInterface::class)]`; every registered contributor runs,
 * for the tenant the request was resolved to. Keep contributors stateless:
 * they are worker singletons. A contributor that throws costs the document
 * its contribution, not the document.
 */
interface LlmsTxtContributorInterface
{
    /**
     * One or two sentences on what the site is, used as the document's
     * blockquote. The first contributor to return a non-empty summary wins;
     * null or '' leaves it to the next one, then to the default line.
     */
    public function summary(?TenantContextInterface $tenantContext): ?string;

    /**
     * Markdown sections placed after the blockquote, before the machine entry
     * points: each one a `## Heading` with its body. '' adds nothing.
     */
    public function sections(?TenantContextInterface $tenantContext): string;
}
