import React from 'react';
import { GlobeAltIcon, NoSymbolIcon } from '@heroicons/react/24/solid';
import Badge from './Badge';

export default function MonitorDomainIcon({ monitor }) {

    let badgeProps = { icon: null, text: '', color: '' };

    if (! monitor.domain_expires_at) {
        badgeProps = {
            icon: <NoSymbolIcon className="h-5 w-5 mr-1 text-red-500" />,
            text: 'No Data',
            color: 'red'
        };
    } else {
        const today = new Date();
        const daysLeft = Math.floor((new Date(monitor.domain_expires_at) - today) / (1000 * 60 * 60 * 24));
        
        switch (true) {
            case daysLeft > 100:
                badgeProps = {
                    icon: <GlobeAltIcon className="h-5 w-5 mr-1 text-green-500" />,
                    text: '100+ days left',
                    color: 'green'
                };
                break;
            case daysLeft <= 100 && daysLeft > 30:
                badgeProps = {
                    icon: <GlobeAltIcon className="h-5 w-5 mr-1 text-blue-500" />,
                    text: `${daysLeft} days left`,
                    color: 'blue'
                };
                break;
            case daysLeft <= 30 && daysLeft > 7:
                badgeProps = {
                    icon: <GlobeAltIcon className="h-5 w-5 mr-1 text-purple-500" />,
                    text: `${daysLeft} days left`,
                    color: 'purple'
                };
                break;
            case daysLeft <= 7 && daysLeft > 1:
                badgeProps = {
                    icon: <GlobeAltIcon className="h-5 w-5 mr-1 text-yellow-500" />,
                    text: `${daysLeft} days left`,
                    color: 'yellow'
                };
                break;
            case daysLeft === 1:
                badgeProps = {
                    icon: <GlobeAltIcon className="h-5 w-5 mr-1 text-pink-500" />,
                    text: `${daysLeft} day left`,
                    color: 'pink'
                };
                break;
            case daysLeft <= 0:
                badgeProps = {
                    icon: <GlobeAltIcon className="h-5 w-5 mr-1 text-red-500" />,
                    text: 'Domain Expired',
                    color: 'red'
                };
                break;
            default:
                return <></>;
        }
    }

    return <Badge icon={badgeProps.icon} text={badgeProps.text} color={badgeProps.color} />;
}
