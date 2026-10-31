import { useForm } from "@inertiajs/react";
import { route } from "ziggy-js";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import React from "react";
import { motion } from "motion/react";
import { Input } from "@/components/ui/input";
import { Eye } from "lucide-react";

export default function Page() {
    const form = useForm({
        email: "",
        password: "",
    });

    async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(route("api.auth.login"));
        console.log(form.data);
        console.log(JSON.stringify(form.errors));
    }

    return (
        <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 via-sky-50 to-indigo-50 dark:from-gray-950 dark:via-gray-900 dark:to-gray-950 p-4">
            {/* Background decorative elements */}
            <div className="absolute inset-0 overflow-hidden pointer-events-none">
                <div className="absolute -top-40 -right-40 w-80 h-80 bg-blue-200/30 dark:bg-blue-900/10 rounded-full blur-3xl" />
                <div className="absolute -bottom-40 -left-40 w-80 h-80 bg-indigo-200/30 dark:bg-indigo-900/10 rounded-full blur-3xl" />
                <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-sky-200/20 dark:bg-sky-900/5 rounded-full blur-3xl" />
            </div>

            <motion.div
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.5, ease: "easeOut" }}
                className="w-full max-w-md relative z-10"
            >
                <Card className="border-blue-100 dark:border-gray-800 shadow-xl shadow-blue-100/50 dark:shadow-black/20">
                    <CardHeader className="text-center space-y-4 pb-2">
                        <motion.div
                            initial={{ scale: 0.8, opacity: 0 }}
                            animate={{ scale: 1, opacity: 1 }}
                            transition={{ delay: 0.2, duration: 0.4 }}
                            className="flex justify-center"
                        >
                            <img
                                src="/mdrrmo-logo.webp"
                                alt="MDRRMO Logo"
                                width={64}
                                height={64}
                                className="shrink-0"
                            />
                        </motion.div>

                        <motion.div
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            transition={{ delay: 0.3, duration: 0.4 }}
                        >
                            <CardTitle className="text-2xl font-bold bg-linear-to-r from-blue-600 to-indigo-600 bg-clip-text text-transparent">
                                MDRRMO
                            </CardTitle>
                            <CardDescription className="text-sm text-muted-foreground mt-1">
                                Municipal Disaster Risk Reduction and Management
                                Office
                            </CardDescription>
                        </motion.div>
                    </CardHeader>

                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            {/* {error && (
                                <motion.div
                                    initial={{ opacity: 0, height: 0 }}
                                    animate={{ opacity: 1, height: "auto" }}
                                    className="flex items-center gap-2 p-3 rounded-lg bg-red-50 dark:bg-red-950/30 text-red-600 dark:text-red-400 text-sm border border-red-200 dark:border-red-900"
                                >
                                    <AlertCircle className="w-4 h-4 shrink-0" />
                                    <span>{error}</span>
                                </motion.div>
                            )} */}

                            <div className="space-y-2">
                                <Label htmlFor="email">Email Address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    placeholder="Enter your email"
                                    value={form.data.email}
                                    onChange={(e) =>
                                        form.setData("email", e.target.value)
                                    }
                                    className="h-11"
                                    autoComplete="email"
                                />
                            </div>

                            <div className="space-y-2">
                                <div className="flex items-center justify-between">
                                    <Label htmlFor="password">Password</Label>
                                    <button
                                        type="button"
                                        className="text-xs text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 font-medium transition-colors"
                                    >
                                        Forgot Password?
                                    </button>
                                </div>
                                <div className="relative">
                                    <Input
                                        id="password"
                                        type="password"
                                        placeholder="Enter your password"
                                        value={form.data.password}
                                        onChange={(e) =>
                                            form.setData(
                                                "password",
                                                e.target.value,
                                            )
                                        }
                                        className="h-11 pr-10"
                                        autoComplete="current-password"
                                    />
                                    <button
                                        type="button"
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground transition-colors"
                                    >
                                        <Eye className="w-4 h-4" />
                                    </button>
                                </div>
                            </div>

                            <Button
                                type="submit"
                                className="w-full h-11 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold shadow-md shadow-blue-500/25 transition-all duration-200"
                                disabled={form.processing}
                            >
                                {form.processing ? (
                                    <motion.div
                                        animate={{ rotate: 360 }}
                                        transition={{
                                            duration: 1,
                                            repeat: Infinity,
                                            ease: "linear",
                                        }}
                                        className="w-5 h-5 border-2 border-white/30 border-t-white rounded-full"
                                    />
                                ) : (
                                    "Sign In"
                                )}
                            </Button>
                        </form>
                    </CardContent>

                    <CardFooter className="justify-center pb-6">
                        <p className="text-sm text-muted-foreground">
                            Don&apos;t have an account?{" "}
                            <button
                                type="button"
                                className="text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300 font-semibold transition-colors"
                            >
                                Register
                            </button>
                        </p>
                    </CardFooter>
                </Card>
            </motion.div>
        </div>
    );
}
