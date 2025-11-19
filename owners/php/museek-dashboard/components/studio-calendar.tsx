"use client"

import { useState } from "react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { ChevronLeft, ChevronRight } from "lucide-react"

export function StudioCalendar() {
  const [currentMonth, setCurrentMonth] = useState(new Date(2025, 4)) // May 2025

  const daysOfWeek = ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"]

  // Get days for the current month view
  const getDaysInMonth = (date: Date) => {
    const year = date.getFullYear()
    const month = date.getMonth()
    const daysInMonth = new Date(year, month + 1, 0).getDate()
    const firstDayOfMonth = new Date(year, month, 1).getDay()

    const prevMonthDays = []
    const currentMonthDays = []
    const nextMonthDays = []

    // Previous month days
    const prevMonth = new Date(year, month, 0)
    const prevMonthDate = prevMonth.getDate()
    for (let i = firstDayOfMonth - 1; i >= 0; i--) {
      prevMonthDays.push({
        date: prevMonthDate - i,
        currentMonth: false,
        hasBooking: false,
      })
    }

    // Current month days
    // Simulate some bookings
    const bookingDates = [1, 2, 3, 8, 9, 15, 16, 23, 30]
    for (let i = 1; i <= daysInMonth; i++) {
      currentMonthDays.push({
        date: i,
        currentMonth: true,
        hasBooking: bookingDates.includes(i),
      })
    }

    // Next month days
    const totalDays = prevMonthDays.length + currentMonthDays.length
    const nextDays = 42 - totalDays > 7 ? 42 - 7 - totalDays : 42 - totalDays
    for (let i = 1; i <= nextDays; i++) {
      nextMonthDays.push({
        date: i,
        currentMonth: false,
        hasBooking: false,
      })
    }

    return [...prevMonthDays, ...currentMonthDays, ...nextMonthDays]
  }

  const days = getDaysInMonth(currentMonth)

  const prevMonth = () => {
    setCurrentMonth(new Date(currentMonth.getFullYear(), currentMonth.getMonth() - 1))
  }

  const nextMonth = () => {
    setCurrentMonth(new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1))
  }

  const formatMonth = (date: Date) => {
    return date.toLocaleDateString("en-US", { month: "long", year: "numeric" })
  }

  const [selectedDate, setSelectedDate] = useState<number | null>(17)

  return (
    <Card className="bg-[#0a0a0a] border-[#222222]">
      <CardHeader className="pb-2">
        <CardTitle>Calendar</CardTitle>
        <CardDescription>Your bookings for the month</CardDescription>
      </CardHeader>
      <CardContent>
        <div className="flex items-center justify-between mb-4">
          <Button variant="ghost" size="icon" onClick={prevMonth}>
            <ChevronLeft className="h-4 w-4" />
          </Button>
          <h3 className="text-lg font-medium">{formatMonth(currentMonth)}</h3>
          <Button variant="ghost" size="icon" onClick={nextMonth}>
            <ChevronRight className="h-4 w-4" />
          </Button>
        </div>

        <div className="grid grid-cols-7 gap-1 text-center mb-2">
          {daysOfWeek.map((day) => (
            <div key={day} className="text-xs font-medium text-gray-400">
              {day}
            </div>
          ))}
        </div>

        <div className="grid grid-cols-7 gap-1">
          {days.map((day, index) => (
            <Button
              key={index}
              variant="ghost"
              className={`h-8 w-full p-0 ${
                !day.currentMonth ? "text-gray-600" : day.hasBooking ? "text-white" : "text-gray-400"
              } ${day.date === selectedDate && day.currentMonth ? "bg-red-600 text-white hover:bg-red-700" : ""} ${
                day.hasBooking && day.currentMonth && day.date !== selectedDate ? "text-red-500" : ""
              }`}
              onClick={() => day.currentMonth && setSelectedDate(day.date)}
            >
              {day.date}
            </Button>
          ))}
        </div>

        <div className="mt-6 p-4 border border-[#222222] rounded-md">
          <h4 className="font-medium mb-2">Saturday, May {selectedDate}, 2025</h4>
          <p className="text-gray-400 text-sm">No bookings for this date</p>
        </div>
      </CardContent>
    </Card>
  )
}
