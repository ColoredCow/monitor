import React from "react";
import ApplicationLogo from "@/Components/ApplicationLogo";
import { Link } from "@inertiajs/react";

export default function Guest({ children }) {
    return (
        <div className="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 px-4 sm:px-0 bg-[#f8fafc] overflow-hidden relative">
            {/* Background decorative elements */}
            <div className="absolute top-0 left-0 w-full h-full overflow-hidden -z-10 bg-radial-[at_50%_50%] from-purple-50/50 to-transparent"></div>
            <div className="absolute -top-24 -left-24 w-96 h-96 bg-purple-200/30 rounded-full blur-3xl animate-pulse"></div>
            <div
                className="absolute -bottom-24 -right-24 w-96 h-96 bg-indigo-200/30 rounded-full blur-3xl animate-pulse"
                style={{ animationDelay: "1s" }}
            ></div>

            <div className="z-10 flex flex-col items-center">
                <Link href="/" className="flex flex-col items-center group">
                    <ApplicationLogo className="w-16 h-12" />
                    <h1 className="text-3xl font-bold tracking-tight text-gray-900 mb-2">
                        ColoredCow Monitor
                    </h1>
                </Link>
            </div>

            <div className="w-full sm:max-w-md z-10 p-8 glass premium-shadow rounded-3xl border border-white/40">
                {children}
            </div>
        </div>
    );
}
