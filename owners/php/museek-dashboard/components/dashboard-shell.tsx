import type React from "react"
import { SidebarInset } from "@/components/ui/sidebar"

interface DashboardShellProps {
  children: React.ReactNode
}

export function DashboardShell({ children }: DashboardShellProps) {
  return (
    <SidebarInset className="flex-1 bg-[#161616]">
      <div className="flex flex-col min-h-screen w-full">{children}</div>
    </SidebarInset>
  )
}
