import React, { useState, useContext } from 'react';
import { Link } from '@inertiajs/react';
import { Transition } from '@headlessui/react';

const DropDownContext = React.createContext();

const Dropdown = ({ children }) => {
    const [open, setOpen] = useState(false);

    const toggleOpen = () => {
        setOpen((previousState) => !previousState);
    };

    return (
        <DropDownContext.Provider value={{ open, setOpen, toggleOpen }}>
            <div className="relative">{children}</div>
        </DropDownContext.Provider>
    );
};

const Trigger = ({ children }) => {
    const { open, setOpen, toggleOpen } = useContext(DropDownContext);

    return (
        <>
            <div onClick={toggleOpen}>{children}</div>

            {open && <div className="fixed inset-0 z-40" onClick={() => setOpen(false)}></div>}
        </>
    );
};

const Content = ({ align = 'right', width = '48', contentClasses = '', children }) => {
    const { open, setOpen } = useContext(DropDownContext);

    let alignmentClasses = 'origin-top';

    if (align === 'left') {
        alignmentClasses = 'origin-top-left left-0';
    } else if (align === 'right') {
        alignmentClasses = 'origin-top-right right-0';
    }

    let widthClasses = '';

    if (width === '48') {
        widthClasses = 'w-48';
    }

    return (
        <>
            <Transition
                show={open}
                enter="transition ease-out duration-150"
                enterFrom="transform opacity-0 scale-95"
                enterTo="transform opacity-100 scale-100"
                leave="transition ease-in duration-100"
                leaveFrom="transform opacity-100 scale-100"
                leaveTo="transform opacity-0 scale-95"
            >
                {open && (
                    <div
                        className={`absolute z-50 mt-1.5 ${alignmentClasses} ${widthClasses}`}
                        onClick={() => setOpen(false)}
                    >
                        <div className={`rounded-lg bg-white shadow-lg border border-gray-200 py-1 ${contentClasses}`}>
                            {children}
                        </div>
                    </div>
                )}
            </Transition>
        </>
    );
};

const DropdownLink = ({ href, method = 'post', as = 'a', children }) => {
    return (
        <Link
            href={href}
            method={method}
            as={as}
            className="block w-full px-4 py-2.5 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:bg-gray-50 transition-colors duration-150 first:rounded-t-lg last:rounded-b-lg"
        >
            {children}
        </Link>
    );
};

Dropdown.Trigger = Trigger;
Dropdown.Content = Content;
Dropdown.Link = DropdownLink;

export default Dropdown;
