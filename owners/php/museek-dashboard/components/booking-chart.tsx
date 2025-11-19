"use client"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { ChartContainer, ChartTooltip, ChartTooltipContent } from "@/components/ui/chart"
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, XAxis, YAxis } from "recharts"

interface BookingData {
  booking_date: string
  total_bookings: number
}

interface BookingChartProps {
  bookingData: BookingData[]
}

export function BookingChart({ bookingData }: BookingChartProps) {
  // Format dates to be more readable
  const formattedData = bookingData.map((item) => ({
    date: new Date(item.booking_date).toLocaleDateString("en-US", {
      month: "short",
      day: "numeric",
    }),
    bookings: item.total_bookings,
  }))

  return (
    <Card className="bg-[#0a0a0a] border-[#222222]">
      <CardHeader className="pb-2">
        <CardTitle>Booking Trend</CardTitle>
      </CardHeader>
      <CardContent>
        <ChartContainer
          config={{
            bookings: {
              label: "Bookings",
              color: "hsl(0, 84%, 50%)",
            },
          }}
          className="h-[300px]"
        >
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={formattedData} margin={{ top: 10, right: 10, left: 0, bottom: 20 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="#222222" />
              <XAxis dataKey="date" tick={{ fill: "#fff" }} axisLine={{ stroke: "#222222" }} />
              <YAxis tick={{ fill: "#fff" }} axisLine={{ stroke: "#222222" }} />
              <ChartTooltip content={<ChartTooltipContent />} />
              <Bar dataKey="bookings" fill="rgba(220, 38, 38, 0.7)" radius={[4, 4, 0, 0]} name="Bookings" />
            </BarChart>
          </ResponsiveContainer>
        </ChartContainer>
      </CardContent>
    </Card>
  )
}
