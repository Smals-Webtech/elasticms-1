<?php

namespace App\Twig;

use Twig\TwigFilter;
use Twig\Extension\AbstractExtension;


class Trade4uExtension extends AbstractExtension
{
    /**
     * @return TwigFilter[]
     */
    public function getFilters(): array
    {
        return array(
            new TwigFilter('trade4uMatch', array($this, 'trade4uMatch')),
        );
    }

    /**
     * Returns contact information foreach matching company domain
     *
     * @param array<mixed> $company
     * @param array<mixed> $opportunity
     *
     * @return array<mixed>
     */
    public function trade4uMatch(array $company, array $opportunity): array
    {
        $contacts = $this->getContacts($company);

        if (empty($contacts)) {
            return []; //no available contact information
        }

        $result = [];

        if(isset($company['domains']))  {
            foreach ($company['domains'] as $domainNumber => $domain) {

                if (!$this->domainMatchOpportunity($domain, $opportunity)) {
                    continue;
                }

                $domainContacts = array_filter($contacts, function (array $contact) use ($domainNumber) {
                    return in_array($domainNumber, $contact['domain_notifications']);
                });

                if (count($domainContacts) > 0) {
                    $result[$domainNumber] = $domainContacts;
                }
            }
        }

        return $result;
    }

    /**
     * Filter out invalid contacts
     *
     * @param array<mixed> $company
     *
     * @return array<mixed>
     */
    private function getContacts(array $company): array
    {
        if(! isset($company['contacts'])) {
            return  [];
        }

        return array_filter($company['contacts'], function(array $contact) {
            if (null == trim($contact['email'])) {
                return false;
            }

            if (null == trim($contact['title'])) {
                return false;
            }

            if (!isset($contact['domain_notifications'])) {
                return false;
            }

            return true;
        });
    }

    /**
     * @param array<mixed> $domain
     * @param array<mixed> $opportunity
     */
    private function domainMatchOpportunity(array $domain, array $opportunity): bool
    {
        if (false === $this->match($domain, $opportunity, 'products')) {
            return false;
        }

        if (false === $this->match($domain, $opportunity, 'activities')) {
            return false;
        }

        if (false === $this->match($domain, $opportunity, 'countries_active') && false === $this->match($domain, $opportunity, 'countries_interested')) {
            return false;
        }

        return true; //domain matches opportunity
    }

    /**
     * @param array<mixed>  $opportunity
     * @param array<mixed>  $domain
     * @param string $field       products, activities, countries_active
     */
    private function match(array $domain, array $opportunity, $field): bool
    {
        if (!isset($opportunity[$field]) || null == $opportunity[$field]) {
            return true;
        }

        if (!isset($domain[$field])) {
            return false;
        }


        foreach ($opportunity[$field] as $link) {
            if (in_array($link, $domain[$field])) {
                return true; //domain field matches
            }
        }

        return false;
    }
}