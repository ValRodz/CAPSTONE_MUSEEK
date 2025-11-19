"use client"

import {
  CalendarIcon,
  CogIcon,
  DollarSignIcon,
  FileTextIcon,
  HomeIcon,
  MessageSquareIcon,
  MusicIcon,
  UsersIcon,
  X,
} from "lucide-react"

import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarRail,
  SidebarTrigger,
  useSidebar,
} from "@/components/ui/sidebar"
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar"
import { Button } from "@/components/ui/button"
import { RefreshCw } from "lucide-react"

export function DashboardSidebar() {
  const { isMobile } = useSidebar()

  const menuItems = [
    { icon: HomeIcon, label: "Dashboard", active: true },
    { icon: CalendarIcon, label: "Bookings" },
    { icon: CalendarIcon, label: "Schedule" },
    { icon: DollarSignIcon, label: "Payments" },
    { icon: UsersIcon, label: "Instructors" },
    { icon: MessageSquareIcon, label: "Feedback" },
    { icon: FileTextIcon, label: "Reports" },
    { icon: CogIcon, label: "Settings" },
    { icon: MusicIcon, label: "Studio Management" },
  ]

  return (
    <Sidebar>
      <SidebarHeader className="border-b border-[#222222]">
        <div className="flex items-center justify-between px-4 py-3">
          <div className="flex items-center gap-2">
            <MusicIcon className="h-5 w-5 text-red-600" />
            <span className="font-semibold text-white text-lg">MuSeek</span>
          </div>
          {isMobile && (
            <SidebarTrigger className="text-white hover:bg-[#222222]">
              <X className="h-5 w-5" />
            </SidebarTrigger>
          )}
        </div>
      </SidebarHeader>
      <SidebarContent>
        <SidebarMenu>
          {menuItems.map((item, index) => (
            <SidebarMenuItem key={index}>
              <SidebarMenuButton isActive={item.active} className={item.active ? "bg-red-600" : "hover:bg-[#222222]"}>
                <item.icon className="h-4 w-4" />
                <span>{item.label}</span>
              </SidebarMenuButton>
            </SidebarMenuItem>
          ))}
        </SidebarMenu>
      </SidebarContent>
      <SidebarFooter className="border-t border-[#222222]">
        <div className="flex items-center gap-3 px-4 py-3">
          <Avatar className="h-8 w-8">
            <AvatarImage
              src="https://storage.googleapis.com/a1aa/image/a85a33fe-4db8-49f9-b589-eb146fafd854.jpg"
              alt="User avatar"
            />
            <AvatarFallback>SO</AvatarFallback>
          </Avatar>
          <div className="flex flex-col text-xs text-gray-400">
            <span className="font-semibold text-white text-[13px]">Studio Owner</span>
            <span>owner@museek.com</span>
          </div>
          <Button variant="ghost" size="icon" className="ml-auto text-gray-400 hover:text-white">
            <RefreshCw className="h-4 w-4" />
            <span className="sr-only">Refresh</span>
          </Button>
        </div>
      </SidebarFooter>
      <SidebarRail />
    </Sidebar>
  )
}
