import { SidebarTrigger } from "@/components/ui/sidebar"
import { Menu } from "lucide-react"

interface DashboardHeaderProps {
  title: string
}

export function DashboardHeader({ title }: DashboardHeaderProps) {
  return (
    <header className="flex items-center h-16 px-6 border-b border-[#222222]">
      <div className="flex items-center gap-4">
        <SidebarTrigger className="text-white hover:bg-[#222222]">
          <Menu className="h-5 w-5" />
        </SidebarTrigger>
        <h1 className="text-xl font-bold">{title}</h1>
      </div>
    </header>
  )
}
