import React from 'react';
import { GlobeAltIcon, NoSymbolIcon } from '@heroicons/react/24/solid';
import Badge from './Badge';
import {
    CHECK_STATUS,
    getCheckStatusBadgeColor,
} from '@/Utils/checkStatusSeverity';

export default function MonitorDomainIcon({ monitor }) {

    let badgeProps = { icon: null, text: '', color: '' };

    if (! monitor.domain_expires_at) {
        badgeProps = {
            icon: <NoSymbolIcon className="h-4 w-4 text-gray-600" />,
            text: 'No Data',
            color: getCheckStatusBadgeColor(CHECK_STATUS.UNKNOWN),
        };
    } else {
        const today = new Date();
        const daysLeft = Math.floor((new Date(monitor.domain_expires_at) - today) / (1000 * 60 * 60 * 24));

        switch (true) {
            case daysLeft > 100:
                badgeProps = {
                    icon: <GlobeAltIcon className="h-4 w-4 text-green-600" />,
                    text: '100+ days left',
                    color: getCheckStatusBadgeColor(CHECK_STATUS.SUCCESS),
                };
                break;
            case daysLeft <= 100 && daysLeft > 30:
                badgeProps = {
                    icon: <GlobeAltIcon className="h-4 w-4 text-blue-600" />,
                    text: `${daysLeft} days left`,
                    color: getCheckStatusBadgeColor(CHECK_STATUS.SUCCESS),
                };
                break;
            case daysLeft <= 30 && daysLeft > 7:
                badgeProps = {
                    icon: <GlobeAltIcon className="h-4 w-4 text-yellow-600" />,
                    text: `${daysLeft} days left`,
                    color: getCheckStatusBadgeColor(CHECK_STATUS.WARNING),
                };
                break;
            case daysLeft <= 7 && daysLeft > 1:
                badgeProps = {
                    icon: <GlobeAltIcon className="h-4 w-4 text-yellow-600" />,
                    text: `${daysLeft} days left`,
                    color: getCheckStatusBadgeColor(CHECK_STATUS.WARNING),
                };
                break;
            case daysLeft === 1:
                badgeProps = {
                    icon: <GlobeAltIcon className="h-4 w-4 text-yellow-600" />,
                    text: `${daysLeft} day left`,
                    color: getCheckStatusBadgeColor(CHECK_STATUS.WARNING),
                };
                break;
            case daysLeft <= 0:
                badgeProps = {
                    icon: <GlobeAltIcon className="h-4 w-4 text-red-600" />,
                    text: 'Domain Expired',
                    color: getCheckStatusBadgeColor(CHECK_STATUS.FAILED),
                };
                break;
            default:
                return <></>;
        }
    }

    return <Badge icon={badgeProps.icon} text={badgeProps.text} color={badgeProps.color} />;
}
